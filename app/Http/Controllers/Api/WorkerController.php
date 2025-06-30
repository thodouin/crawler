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
use Illuminate\Support\Facades\Http;

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
    public function taskUpdate(Request $request)
    {
        // 1. Mise à jour de la validation pour accepter les nouveaux détails
        $baseRules = [
            'worker_identifier' => 'required|string|exists:crawler_workers,worker_identifier',
            'site_id_laravel' => 'required|integer|exists:sites,id',
            'task_type' => 'required|string|in:crawl,check_existence,sitemap_crawl',
            'message' => 'nullable|string',
            'details' => 'nullable|array', // On accepte toujours le tableau 'details'
        ];

        // 2. On ajoute des règles spécifiques en fonction du type de tâche
        $specificRules = [];
        $taskType = $request->input('task_type');

        if ($taskType === 'check_existence') {
            $specificRules = [
                'existence_result' => 'required|string|in:exists,not_found,error',
                'details.technology' => 'nullable|string',
                'details.analytics_tools' => 'nullable|string',
                'details.language' => 'nullable|string',
            ];
        } elseif ($taskType === 'sitemap_crawl') {
            $specificRules = [
                'sitemap_results' => 'required|array',
            ];
        } elseif ($taskType === 'crawl') {
            $specificRules = [
                'crawl_outcome' => 'required|string',
                'details.pages_crawled' => 'required|integer',
                'details.depth_requested' => 'required|integer',
            ];
        }

        $validator = Validator::make($request->all(), array_merge($baseRules, $specificRules));
    
        if ($validator->fails()) {
            Log::error("TaskUpdate Validation Failed", $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $validated = $validator->validated();
        $site = Site::find($validated['site_id_laravel']);
        $worker = CrawlerWorker::where('worker_identifier', $validated['worker_identifier'])->first();
    
        if (!$site || !$worker) { /* ... */ }
    
        Log::info("Callback TaskUpdate reçu pour Site ID {$site->id} par Worker {$worker->worker_identifier}. Tâche: {$validated['task_type']}");
    
        // 2. Initialisation du payload pour le callback
        $callbackPayload = null;

        // 3. Logique de mise à jour et préparation du callback
        if ($validated['task_type'] === 'check_existence') {
            Log::info('[DEBUG] Entrée dans le bloc "check_existence".');
            $site->existence_status = $validated['existence_result'];
            $site->last_existence_check_at = now();
            $site->status_api = null;
            $site->crawler_worker_id = null;
            $site->last_api_response = "Existence check: " . $validated['existence_result'];
            
            $callbackPayload = [
                'url' => $site->url,
                'task_type' => 'check_existence',
                'existence_result' => $validated['existence_result'],
                'details' => $validated['details'] ?? null,
            ];
            Log::info('[DEBUG] Payload pour "check_existence" préparé.', $callbackPayload);

        } elseif ($validated['task_type'] === 'sitemap_crawl') {
            $pageCount = count($validated['sitemap_results'] ?? []);
            $site->status_api = SiteStatus::COMPLETED_BY_API;
            $site->last_api_response = "Sitemap crawl: {$pageCount} pages.";
            $site->crawler_worker_id = null;
            
            $callbackPayload = [
                'url' => $site->url,
                'task_type' => 'sitemap_crawl',
                'sitemap_page_count' => $pageCount,
            ];

        } elseif ($validated['task_type'] === 'crawl') {
            $site->crawler_worker_id = null; // On libère toujours le site
            
            if (($validated['crawl_outcome'] ?? '') === 'completed_successfully') {
                $site->status_api = SiteStatus::COMPLETED_BY_API;
                $site->last_api_response = $validated['message'] ?? 'Crawl terminé avec succès.';
                
                $callbackPayload = [
                    'url' => $site->url,
                    'task_type' => 'crawl',
                    'crawl_page_count' => $validated['details']['pages_crawled'] ?? 0,
                    'crawl_max_depth_used' => $validated['details']['depth_requested'] ?? $site->max_depth,
                ];
            } else {
                $site->status_api = SiteStatus::FAILED_PROCESSING_BY_API;
                $site->last_api_response = $validated['message'] ?? 'Le crawl a échoué.';
                // On peut aussi envoyer un callback en cas d'échec si on le souhaite
            }
        }
    
        $site->save();
        if (class_exists(SiteStatusUpdated::class)) { SiteStatusUpdated::dispatch($site->fresh()); }

        // 4. Envoi du callback unifié
        if ($callbackPayload) {
            Log::info("[DEBUG] Un payload de callback existe. Tentative d'envoi.");
            $this->sendGenericCallback($callbackPayload);
        } else {
            Log::info("[DEBUG] Aucun payload de callback à envoyer pour la tâche {$validated['task_type']}."); // Log 5
        }
    
        // --- Libérer le worker ---
        if ($worker->current_site_id_processing == $site->id) {
            $worker->current_site_id_processing = null;
            $worker->status = WorkerStatus::ONLINE_IDLE;
            $worker->save();
            Log::info("Worker [{$worker->worker_identifier}] libéré après tâche sur Site ID {$site->id}.");
            if (class_exists(CrawlerWorkerStatusChanged::class)) {
                CrawlerWorkerStatusChanged::dispatch($worker->fresh());
            }
        } else {
            Log::warning("Worker [{$worker->worker_identifier}] a terminé Site ID {$site->id}, mais il était marqué comme traitant un autre site ou était déjà libre.");
        }
    
        return response()->json(['message' => 'Statut de la tâche mis à jour et callback envoyé.']);
    }

    private function sendGenericCallback(array $payload): void
    {
        $callbackUrl = config('services.client_app.callback_url'); // Utilise une clé générique
        if (!$callbackUrl) {
            Log::error("URL de callback générique non configurée (services.client_app.callback_url).");
            return;
        }

        Log::info("[WORKER->MUSÉE] Préparation de l'envoi du payload de callback : ", $payload);

        try {
            Http::post($callbackUrl, $payload);
            Log::info("Callback générique envoyé avec succès pour l'URL {$payload['url']}");
        } catch (\Exception $e) {
            Log::error("Échec de l'envoi du callback générique pour l'URL {$payload['url']}: " . $e->getMessage());
        }
    }
}