<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\CrawlerWorker; // Important
use App\Enums\SiteStatus;
use App\Enums\SitePriority;
use App\Enums\WorkerStatus; // Pour la mise à jour du statut
use App\Events\SiteStatusUpdated; // Pour notifier Filament
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WorkerApiController extends Controller
{
    /**
     * NOUVELLE MÉTHODE : Récupère une tâche de type "check_existence".
     */
    public function getExistenceCheckTask(Request $request)
    {
        return $this->getTaskForWorker($request, 'check_existence');
    }

    /**
     * NOUVELLE MÉTHODE : Récupère une tâche de type "crawl".
     */
    public function getCrawlTask(Request $request)
    {
        return $this->getTaskForWorker($request, 'crawl');
    }

    /**
     * LOGIQUE COMMUNE : Factorisée pour éviter la duplication de code.
     * C'est ici que toute la magie opère.
     */
    private function getTaskForWorker(Request $request, string $taskType)
    {
        $validator = Validator::make($request->all(), [
            'worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $worker = CrawlerWorker::where('worker_identifier', $request->worker_identifier)->first();

        // On s'assure que le worker est bien LIBRE
        if ($worker->status !== WorkerStatus::ONLINE_IDLE) {
             Log::info("Worker [{$worker->worker_identifier}] a demandé une tâche de type '{$taskType}' mais est déjà {$worker->status->value}.");
             return response()->json(['data' => []]);
        }

        // On cherche un site assigné à ce worker, prêt, ET DU BON TYPE !
        $siteToProcess = Site::query()
            ->where('crawler_worker_id', $worker->id)
            ->where('status_api', SiteStatus::PENDING_SUBMISSION)
            ->where('task_type', $taskType) // <-- FILTRE CLÉ !
            ->orderByRaw("CASE WHEN priority = ? THEN 1 WHEN priority = ? THEN 2 WHEN priority = ? THEN 3 ELSE 4 END", ['urgent', 'normal', 'low'])
            ->orderBy('updated_at', 'asc')
            ->first();

        if (!$siteToProcess) {
            return response()->json(['data' => []]);
        }

        // Mettre à jour le statut du site
        $siteToProcess->status_api = SiteStatus::SUBMITTED_TO_API;
        $siteToProcess->last_sent_to_api_at = now();
        $siteToProcess->fastapi_job_id = 'task_' . uniqid(); 
        $siteToProcess->last_api_response = "Tâche ({$taskType}) récupérée par le worker {$worker->name}.";
        $siteToProcess->save();

        if (class_exists(SiteStatusUpdated::class)) {
            SiteStatusUpdated::dispatch($siteToProcess->fresh());
        }

        // Mettre à jour et lier le worker
        $worker->status = WorkerStatus::ONLINE_BUSY;
        $worker->current_site_id_processing = $siteToProcess->id;
        $worker->save();
        
        if (class_exists(CrawlerWorkerStatusChanged::class)) {
            CrawlerWorkerStatusChanged::dispatch($worker->fresh());
        }
        
        $taskData = [
            'id' => $siteToProcess->id,
            'url' => $siteToProcess->url,
            'priority' => $siteToProcess->priority->value,
            'fastapi_job_id' => $siteToProcess->fastapi_job_id,
            'task_type' => $siteToProcess->task_type, // On le renvoie quand même, c'est une bonne pratique
        ];

        return response()->json(['data' => [$taskData]]);
    }

    /**
     * VOUS POUVEZ MAINTENANT SUPPRIMER L'ANCIENNE MÉTHODE getTasks()
     * public function getTasks(Request $request) { ... }
     */
}