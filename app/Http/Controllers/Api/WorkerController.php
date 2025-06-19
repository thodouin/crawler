<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlerWorker;
use App\Enums\SiteStatus;
use App\Events\SiteStatusUpdated;
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
            'system_info'                 => 'nullable|array',
            'system_info.platform'        => 'sometimes|required_with:system_info|string|max:255',
            'system_info.os_type'         => 'sometimes|required_with:system_info|string|max:255',
            'system_info.os_release'      => 'sometimes|required_with:system_info|string|max:255',
            'system_info.total_memory_gb' => 'sometimes|required_with:system_info|numeric|min:0',
            'system_info.free_memory_gb'  => 'sometimes|required_with:system_info|numeric|min:0',
            'system_info.cpu_cores'       => 'sometimes|required_with:system_info|integer|min:1',
            'system_info.cpu_model'       => 'sometimes|required_with:system_info|string|max:255',
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
                'system_info' => $data['system_info'] ?? null,
            ]
        );
        Log::info("Worker [{$worker->worker_identifier}] enregistré/mis à jour: {$worker->name} at {$worker->ws_protocol}://{$worker->ip_address}:{$worker->port}");

        if (class_exists(CrawlerWorkerStatusChanged::class)) {
           CrawlerWorkerStatusChanged::dispatch($worker); // <<<< CORRIGÉ ICI
        }
            
        return response()->json(['message' => 'Worker enregistré', 'laravel_worker_id' => $worker->id], 200); // 200 pour update, 201 pour create
    }
    

    public function heartbeat(Request $request) {
        $validator = Validator::make($request->all(), [
            'worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier',
            'system_info'       => 'nullable|array',
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $worker = CrawlerWorker::where('worker_identifier', $request->worker_identifier)->firstOrFail();
        $worker->last_heartbeat_at = now();
        // Si le worker était OFFLINE et envoie un heartbeat, le remettre IDLE
        if($worker->status === WorkerStatus::OFFLINE) {
            $worker->status = WorkerStatus::ONLINE_IDLE;
        }
        if ($request->has('system_info') && is_array($request->input('system_info'))) {
            $worker->system_info = array_merge((array)$worker->system_info, $request->input('system_info'));
        }
        $worker->save();
        Log::info("Heartbeat reçu du worker [{$worker->worker_identifier}]");
        if (class_exists(CrawlerWorkerStatusChanged::class)) CrawlerWorkerStatusChanged::dispatch($worker->fresh());
        return response()->json(['message' => 'Heartbeat reçu']);
    }

    // Appelé par FastAPI quand un crawl est terminé ou a échoué
    public function taskUpdate(Request $request) {
        $validator = Validator::make($request->all(), [
            'worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier',
            'site_id_laravel' => 'required|integer|exists:sites,id',
            'task_type' => 'required|string|in:crawl,check_existence',
            'crawl_outcome' => 'required_if:task_type,crawl|string|in:completed_successfully,failed_during_crawl,error_before_start',
            'existence_result' => 'required_if:task_type,check_existence|string|in:exists,not_found,error',
            'message' => 'nullable|string',
            'details' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $site = Site::find($validated['site_id_laravel']);
        $worker = CrawlerWorker::where('worker_identifier', $validated['worker_identifier'])->first();

        if (!$site || !$worker) {
            Log::error("Callback TaskUpdate: Site ou Worker non trouvé.", $request->all());
            return response()->json(['message' => 'Site ou Worker non trouvé'], 404);
        }

        Log::info("Callback TaskUpdate reçu pour Site ID {$site->id} par Worker {$worker->worker_identifier}. Tâche: {$validated['task_type']}");

        // --- Logique de mise à jour en fonction du type de tâche ---
        if ($validated['task_type'] === 'check_existence') {
            
            // 1. On enregistre le résultat de la vérification
            $site->existence_status = $validated['existence_result'];
            $site->last_existence_check_at = now();

            // 2. MODIFICATION : On remet le statut en attente d'une *nouvelle* assignation
            $site->status_api = SiteStatus::PENDING_ASSIGNMENT;

            // 3. MODIFICATION : On met à jour le message pour plus de clarté
            $site->last_api_response = "Vérification d'existence terminée (" . $validated['existence_result'] . "). Prêt pour une nouvelle tâche (ex: crawl).";

            // 4. TRÈS IMPORTANT : On désassigne le worker du site !
            // Le site est maintenant de retour dans le pool commun, il n'appartient plus à ce worker.
            $site->crawler_worker_id = null;

        } elseif ($validated['task_type'] === 'crawl') {
            // Cette logique reste inchangée : une tâche de crawl est finale.
            if ($validated['crawl_outcome'] === 'completed_successfully') {
                $site->status_api = SiteStatus::COMPLETED_BY_API;
            } else {
                $site->status_api = SiteStatus::FAILED_PROCESSING_BY_API;
            }
            $site->last_api_response = "Crawl Callback: {$validated['crawl_outcome']} - " . ($validated['message'] ?? json_encode($validated['details']));
        }

        $site->save();
        if (class_exists(SiteStatusUpdated::class)) {
            SiteStatusUpdated::dispatch($site->fresh());
        }

        // --- Libérer le worker (logique commune) ---
        // Cette partie du code n'a pas besoin de changer. Elle libère le worker de sa tâche *actuelle*.
        if ($worker->current_site_id_processing == $site->id) {
            $worker->current_site_id_processing = null;
            $worker->status = WorkerStatus::ONLINE_IDLE;
            $worker->save();
            Log::info("Worker [{$worker->worker_identifier}] libéré après tâche sur Site ID {$site->id}.");
            if (class_exists(CrawlerWorkerStatusChanged::class)) {
                CrawlerWorkerStatusChanged::dispatch($worker->fresh());
            }
        } else {
            Log::warning("Worker [{$worker->worker_identifier}] a terminé Site ID {$site->id}, mais il était marqué comme traitant un autre site ou déjà libre.");
        }

        return response()->json(['message' => 'Statut de la tâche mis à jour avec succès']);
    }
}