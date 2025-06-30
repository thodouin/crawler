<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\CrawlerWorker; // Important
use App\Models\TaskType;
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

    public function getSitemapTask(Request $request)
    {
        return $this->getTaskForWorker($request, 'sitemap_crawl');
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

        if ($validator->fails()) { return response()->json(['errors' => $validator->errors()], 422); }

        $worker = CrawlerWorker::where('worker_identifier', $request->worker_identifier)->first();

        if ($worker->status !== WorkerStatus::ONLINE_IDLE || !is_null($worker->current_site_id_processing)) {
            Log::info("Worker [{$worker->worker_identifier}] demande une tâche '{$taskType}' mais est déjà occupé.");
            return response()->json(['data' => []]);
        }

        $siteToProcess = null;

        \DB::transaction(function () use ($worker, $taskType, &$siteToProcess) {
            
            $priorityOrder = "CASE WHEN priority = ? THEN 1 WHEN priority = ? THEN 2 ELSE 3 END";
            
            // Priorité 1 : Tâches déjà assignées (pour le lot initial)
            $siteToProcess = Site::query()
                ->where('crawler_worker_id', $worker->id)
                ->where('status_api', SiteStatus::PENDING_SUBMISSION)
                ->where('task_type', $taskType)
                ->orderByRaw($priorityOrder, [SitePriority::URGENT->value, SitePriority::NORMAL->value])
                ->orderBy('updated_at', 'asc')
                ->lockForUpdate()
                ->first();

            // === LA PARTIE LA PLUS IMPORTANTE ===
            // Priorité 2 : Si pas de tâche assignée, s'auto-assigner une tâche de la file d'attente
            if (!$siteToProcess) {
                $siteToProcess = Site::query()
                    ->where('status_api', SiteStatus::PENDING_ASSIGNMENT) // On cherche dans la file d'attente générale
                    ->where('task_type', $taskType)
                    ->whereNull('crawler_worker_id') // Qui n'a pas encore de worker
                    ->orderByRaw($priorityOrder, [SitePriority::URGENT->value, SitePriority::NORMAL->value])
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->first();
                
                if ($siteToProcess) {
                    // Le worker se l'approprie !
                    $siteToProcess->crawler_worker_id = $worker->id;
                    Log::info("Worker [{$worker->worker_identifier}] s'est auto-assigné le Site ID {$siteToProcess->id} qui était en attente.");
                }
            }

            if ($siteToProcess) {
                // Logique commune pour marquer le site et le worker comme occupés
                $siteToProcess->status_api = SiteStatus::SUBMITTED_TO_API;
                $siteToProcess->save();

                $worker->status = WorkerStatus::ONLINE_BUSY;
                $worker->current_site_id_processing = $siteToProcess->id;
                $worker->save();
                
                if (class_exists(SiteStatusUpdated::class)) SiteStatusUpdated::dispatch($siteToProcess->fresh());
                if (class_exists(CrawlerWorkerStatusChanged::class)) CrawlerWorkerStatusChanged::dispatch($worker->fresh());
            }
        });

        if (!$siteToProcess) {
            return response()->json(['data' => []]);
        }

        // Préparer et renvoyer la tâche...
        $taskData = [
            'id' => $siteToProcess->id,
            'url' => $siteToProcess->url,
            'priority' => $siteToProcess->priority->value,
            'task_type' => $siteToProcess->task_type,
            'max_depth' => $siteToProcess->max_depth,
        ];
        return response()->json(['data' => [$taskData]]);
    }

    /**
     * Récupère la définition des champs de formulaire pour un type de tâche donné.
     */
    public function getTaskTypeFields(string $slug)
    {
        $taskType = TaskType::where('slug', $slug)->where('is_active', true)->first();

        if (!$taskType) {
            return response()->json(['message' => 'Type de tâche non trouvé ou inactif.'], 404);
        }

        return response()->json($taskType->required_fields ?? []);
    }
}

