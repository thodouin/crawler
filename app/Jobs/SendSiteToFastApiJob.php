<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\User; // Renommé en CrawlerWorker dans la migration, mais le modèle User sert pour les "serveurs logiques"
use App\Models\CrawlerWorker; // Modèle pour les workers FastAPI enregistrés
use App\Services\FastApiService;
use App\Enums\SiteStatus;
use App\Enums\WorkerStatus; // Pour le statut du CrawlerWorker
use App\Events\SiteStatusUpdated;
use App\Events\CrawlerWorkerStatusChanged; // Nouvel événement pour le statut du worker
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Cache\Lock;

class SendSiteToFastApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1; // Si la soumission à FastAPI est rapide (ack/nack), 1 try peut suffire.
                      // Si le crawl lui-même est fait dans ce job, il faut plus de tries.
    public $backoff = [300, 600, 1800]; // 5min, 10min, 30min
    public Site $site;
    public int $maxDepth;
    public $deleteWhenMissingModels = true;

    public function __construct(Site $site, int $maxDepth = 0)
    {
        $this->site = $site;
        $this->maxDepth = $maxDepth;
    }

    // Méthodes helper pour dispatcher les événements
    protected function dispatchSiteUpdate(): void
    {
        if (class_exists(SiteStatusUpdated::class)) {
            SiteStatusUpdated::dispatch($this->site->fresh());
        }
    }

    protected function dispatchWorkerUpdate(?CrawlerWorker $workerInstance): void
    {
        if ($workerInstance && class_exists(CrawlerWorkerStatusChanged::class)) {
            CrawlerWorkerStatusChanged::dispatch($workerInstance->fresh());
        }
    }

    public function handle(FastApiService $fastApiService): void
    {
        Log::info("Job Handle: Démarrage pour Site ID {$this->site->id} (assigné au CrawlerWorker ID {$this->site->crawler_worker_id}). Profondeur: {$this->maxDepth}. Tentative #{$this->attempts()}");

        if (!$this->site->crawler_worker_id) {
            Log::error("Job Handle: Site ID {$this->site->id} n'a pas de crawler_worker_id. Échec.");
            $this->failAndReleaseWorker(new \Exception("Site ID {$this->site->id} n'a pas de worker assigné."), null);
            return;
        }

        $worker = CrawlerWorker::find($this->site->crawler_worker_id);
        if (!$worker) {
            Log::error("Job Handle: CrawlerWorker ID {$this->site->crawler_worker_id} pour Site ID {$this->site->id} non trouvé. Échec.");
            $this->failAndReleaseWorker(new \Exception("CrawlerWorker ID {$this->site->crawler_worker_id} non trouvé."), null); // Pas de worker à libérer
            return;
        }

        /** @var Lock|null $lock */
        $lock = null;
        $lockAcquired = false;

        try {
            $lock = Cache::lock('crawler_worker_processing_lock_' . $worker->id, 20); // Lock sur l'ID du worker
            if (!$lock->get()) {
                Log::info("Job Handle: Verrou non obtenu pour Worker ID {$worker->id}, Site ID {$this->site->id}. Remise en file.");
                $this->release(45 + rand(0,15)); // Jitter pour éviter thundering herd
                return; 
            }
            $lockAcquired = true;

            $worker->refresh(); // Obtenir l'état le plus récent du worker
            if ($worker->status === WorkerStatus::ONLINE_BUSY && $worker->current_site_id_processing !== $this->site->id) {
                Log::info("Job Handle: Worker ID {$worker->id} ({$worker->name}) est PRIS par Site ID {$worker->current_site_id_processing}. Site ID {$this->site->id} est relâché.");
                $this->release(75 + rand(0,15));
                return;
            }

            // Marquer le Worker comme PRIS et le Site comme en traitement
            $worker->status = WorkerStatus::ONLINE_BUSY;
            $worker->current_site_id_processing = $this->site->id;
            $worker->save();
            $this->dispatchWorkerUpdate($worker);

            $originalSiteStatus = $this->site->status_api;
            $this->site->status_api = SiteStatus::PROCESSING_BY_API;
            $this->site->last_api_response = "Envoi commande WebSocket à worker {$worker->name} (ID: {$worker->worker_identifier})...";
            $this->site->save();
            if ($originalSiteStatus !== $this->site->status_api) $this->dispatchSiteUpdate();

            // Appel au service FastAPI
            $result = $fastApiService->sendCrawlCommandViaWebSocket($this->site, $this->maxDepth);
            
            // Le FastApiService met à jour le statut du site (SUBMITTED_TO_API ou FAILED_API_SUBMISSION) et le sauvegarde.
            // On rediffuse l'événement pour le site.
            $this->dispatchSiteUpdate();

            if (!$result['success']) {
                Log::warning("Job Handle: Échec de la commande WebSocket pour Site ID {$this->site->id} vers Worker {$worker->worker_identifier}. Message: {$result['message']}");
                // Si l'erreur est critique, faire échouer le job. La méthode failed() s'occupera de libérer le worker.
                if (str_contains($result['message'], 'FastAPI WebSocket URL non définie') || str_contains($result['message'], 'Exception de connexion WebSocket')) {
                    $this->fail(new \Exception("Erreur critique soumission WebSocket Site ID {$this->site->id}: " . ($result['message'] ?? 'Erreur inconnue')));
                    return; 
                }
                // Pour d'autres erreurs (FastAPI refuse, etc.), le job va se terminer ici.
                // Le worker sera libéré dans le finally si ce n'est pas un fail().
                // Si $tries > 1, il sera retenté.
            } else {
                Log::info("Job Handle: Commande WebSocket pour Site ID {$this->site->id} acceptée par Worker {$worker->worker_identifier}. Attente callback FastAPI.");
                // Le statut du site est SUBMITTED_TO_API. Le worker Laravel reste PRIS.
                // La libération du worker se fera via le webhook de FastAPI.
            }

        } catch (Throwable $e) {
            Log::error("Job Handle: Exception majeure pour Site ID {$this->site->id} avec Worker ID {$worker->id}. Erreur: " . $e->getMessage(), ['trace' => Str::limit($e->getTraceAsString(), 1000)]);
            $this->failAndReleaseWorker($e, $worker, $lock, $lockAcquired); // Utiliser une méthode helper
            return;
        } finally {
            // Libérer le verrou si acquis
            if ($lockAcquired && $lock) {
                $lock->release();
                Log::info("Job Handle (finally): Verrou pour Worker ID " . ($worker->id ?? 'inconnu') . " libéré.");
            }
            // La libération du worker se fait maintenant via webhook ou dans failed(),
            // ou si une exception a été attrapée et que le job est fail() par cette exception.
            // Si $result['success'] est false et que le job n'a pas été fail(), il faut libérer ici.
            if (isset($result) && !$result['success'] && isset($worker) && $worker instanceof CrawlerWorker && !$this->job->hasFailed() && !$this->job->isReleased()) {
                if ($worker->current_site_id_processing == $this->site->id) {
                    $worker->status = WorkerStatus::ONLINE_IDLE;
                    $worker->current_site_id_processing = null;
                    $worker->save();
                    $this->dispatchWorkerUpdate($worker);
                    Log::info("Job Handle (finally - après échec soumission WS non-fail): Worker ID {$worker->id} marqué LIBRE.");
                }
            }
        }
    }

    // Méthode helper pour centraliser la logique de fail + libération worker
    protected function failAndReleaseWorker(Throwable $exception, ?CrawlerWorker $worker, ?Lock $lock = null, bool $lockAcquired = false): void
    {
        if ($lockAcquired && $lock) { $lock->release(); } // Libérer le verrou d'abord
        if ($worker && $worker->current_site_id_processing === $this->site->id) {
            $worker->status = WorkerStatus::ONLINE_IDLE;
            $worker->current_site_id_processing = null;
            $worker->save();
            $this->dispatchWorkerUpdate($worker);
        }
        $this->fail($exception);
    }

    public function failed(Throwable $exception): void {
        Log::error("Job Failed: Échec définitif pour Site ID {$this->site->id} (assigné à CrawlerWorker ID: {$this->site->crawler_worker_id})", [/* ... */]);
        $siteToUpdate = Site::find($this->site->id);
        if($siteToUpdate){
             $originalStatus = $siteToUpdate->status_api;
             $siteToUpdate->status_api = SiteStatus::FAILED_API_SUBMISSION;
             $siteToUpdate->last_api_response = 'Échec Job (WebSocket): ' . Str::limit($exception->getMessage(), 250);
             $siteToUpdate->saveQuietly();
             if ($originalStatus !== SiteStatus::FAILED_API_SUBMISSION) $this->dispatchSiteUpdate();

             if ($siteToUpdate->crawler_worker_id) {
                 $workerToFree = CrawlerWorker::find($siteToUpdate->crawler_worker_id);
                 if ($workerToFree && $workerToFree->current_site_id_processing === $siteToUpdate->id) {
                     $workerToFree->status = WorkerStatus::ONLINE_IDLE;
                     $workerToFree->current_site_id_processing = null;
                     $workerToFree->save();
                     $this->dispatchWorkerUpdate($workerToFree);
                 }
             }
        }
    }
}