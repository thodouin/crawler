<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlerWorker;
use App\Models\Site;
use App\Enums\WorkerStatus;     // Pour notifier Filament
use App\Events\CrawlerWorkerStatusChanged;// Renommer en WorkerStatusChanged ou garder pour admin
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WorkerController extends Controller 
{
    public function register(Request $request) {
        $validator = Validator::make($request->all(), [
            'worker_identifier' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'ws_protocol' => 'nullable|string|in:ws,wss',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();

        $worker = CrawlerWorker::updateOrCreate(
            ['worker_identifier' => $data['worker_identifier']],
            [
                'name' => $data['name'],
                'ws_protocol' => $data['ws_protocol'] ?? config('services.fastapi.default_ws_scheme', 'ws'),
                'status' => WorkerStatus::ONLINE_IDLE,
                'last_heartbeat_at' => now(),
                'current_site_id_processing' => null,
            ]
        );
        Log::info("Worker [{$worker->worker_identifier}] enregistré/mis à jour: {$worker->name} at {$worker->ws_protocol}://{$worker->ip_address}:{$worker->port}");

        if (class_exists(CrawlerWorkerStatusChanged::class)) {
           CrawlerWorkerStatusChanged::dispatch($worker); // <<<< CORRIGÉ ICI
        }
            
        return response()->json(['message' => 'Worker enregistré', 'laravel_worker_id' => $worker->id], 200); // 200 pour update, 201 pour create
    }
    

    public function heartbeat(Request $request) {
        $validator = Validator::make($request->all(), ['worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier']);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $worker = CrawlerWorker::where('worker_identifier', $request->worker_identifier)->firstOrFail();
        $worker->last_heartbeat_at = now();
        // Si le worker était OFFLINE et envoie un heartbeat, le remettre IDLE
        if($worker->status === WorkerStatus::OFFLINE) {
            $worker->status = WorkerStatus::ONLINE_IDLE;
        }
        $worker->save();
        Log::info("Heartbeat reçu du worker [{$worker->worker_identifier}]");
        if (class_exists(CrawlerWorkerStatusChanged::class)) CrawlerWorkerStatusChanged::dispatch($worker->fresh());
        return response()->json(['message' => 'Heartbeat reçu']);
    }

    // Appelé par FastAPI quand un crawl est terminé ou a échoué
    public function taskUpdate(Request $request) {
        $validator = Validator::make($request->all(), [
            'site_id_laravel' => 'required|integer|exists:sites,id',
            'crawl_outcome' => 'required|string|in:completed_successfully,failed_during_crawl,error_before_start',
            'message' => 'nullable|string',
            'details' => 'nullable|array', // Résumé des pages, erreurs, etc.
            'worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier', // Pour savoir quel worker a terminé
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        $site = Site::find($request->site_id_laravel);
        $worker = CrawlerWorker::where('worker_identifier', $request->worker_identifier)->first();

        if (!$site || !$worker) {
            Log::error("Callback TaskUpdate: Site ou Worker non trouvé.", $request->all());
            return response()->json(['message' => 'Site ou Worker non trouvé'], 404);
        }

        Log::info("Callback TaskUpdate reçu pour Site ID {$site->id} par Worker {$worker->worker_identifier}. Outcome: {$request->crawl_outcome}");

        // Mettre à jour le statut du site
        if ($request->crawl_outcome === 'completed_successfully') {
            $site->status_api = SiteStatus::COMPLETED_BY_API;
        } else {
            $site->status_api = SiteStatus::FAILED_PROCESSING_BY_API;
        }
        $site->last_api_response = "FastAPI Callback: {$request->crawl_outcome} - " . ($request->message ?? json_encode($request->details));
        $site->save();
        if (class_exists(SiteStatusUpdated::class)) SiteStatusUpdated::dispatch($site->fresh());

        // Libérer le worker s'il traitait bien ce site
        if ($worker->current_site_id_processing == $site->id) {
            $worker->current_site_id_processing = null;
            $worker->status = WorkerStatus::ONLINE_IDLE;
            $worker->save();
            Log::info("Worker [{$worker->worker_identifier}] libéré après tâche sur Site ID {$site->id}.");
            if (class_exists(CrawlerWorkerStatusChanged::class)) CrawlerWorkerStatusChanged::dispatch($worker->fresh());
        } else {
            Log::warning("Worker [{$worker->worker_identifier}] a terminé Site ID {$site->id}, mais il était marqué comme traitant Site ID {$worker->current_site_id_processing} ou libre.");
        }
        return response()->json(['message' => 'Statut de la tâche mis à jour']);
    }
}