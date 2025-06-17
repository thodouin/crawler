<?php

namespace App\Services;

use App\Models\Site;
use App\Models\CrawlerWorker; // Utiliser le modèle CrawlerWorker
use App\Enums\SiteStatus;
use App\Enums\WorkerStatus; // Si vous l'utilisez pour le worker Laravel
use Illuminate\Support\Facades\Http; // Garder pour un éventuel appel API HTTP pour le webhook
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use WebSocket\Client as WebSocketClient; // textalk/websocket
use WebSocket\ConnectionException;

class FastApiService
{
    protected string $laravelAppUrl;
    protected ?string $globalApiKey;

    public function __construct()
    {
        // Note: config('services.fastapi.url') n'est plus utilisé directement ici
        // car l'URL du worker est dans la table CrawlerWorker.
        // config('services.fastapi.key') pourrait être une clé globale si FastAPI la requiert pour WS.
        // $this->globalApiKey = config('services.fastapi.key'); // Si vous avez une clé API globale pour FastAPI
        $this->laravelAppUrl = rtrim(config('app.url'), '/'); // URL de base de votre app Laravel
    }

    /**
     * Envoie une commande de crawl à une instance FastAPI spécifique via WebSocket.
     *
     * @param Site $site L'objet Site Laravel (qui a une relation crawlerWorker).
     * @param int $maxDepth La profondeur de crawl.
     * @param int $maxConcurrency Concurrence des pages pour ce crawl par FastAPI.
     * @param string|null $outputDir Répertoire de sortie de base sur le worker FastAPI.
     * @return array{success: bool, message: string, fastapi_raw_response: ?array}
     */
    public function sendCrawlCommandViaWebSocket(
        Site $site, // Le site contient déjà crawler_worker_id
        int $maxDepth = 0,
        int $maxConcurrency = 8,
        ?string $outputDir = null
    ): array {
        $defaultResponse = ['success' => false, 'message' => 'Initialisation envoi WS échouée.', 'fastapi_raw_response' => null];

        $site->loadMissing('crawlerWorker');
        $worker = $site->crawlerWorker;

        if (!$worker || empty($worker->ip_address) || empty($worker->port) || empty($worker->ws_protocol)) {
            $msg = "Infos de connexion WebSocket incomplètes pour CrawlerWorker ID {$site->crawler_worker_id}. Site ID: {$site->id}";
            Log::error($msg);
            $site->fill(['status_api' => SiteStatus::FAILED_API_SUBMISSION, 'last_api_response' => $msg])->saveQuietly();
            // Dispatcher l'événement de mise à jour du site ici si nécessaire
            if (class_exists(\App\Events\SiteStatusUpdated::class)) { \App\Events\SiteStatusUpdated::dispatch($site->fresh()); }
            $defaultResponse['message'] = $msg;
            return $defaultResponse;
        }

        $wsEndpointPath = config('services.fastapi.ws_endpoint_path', '/ws');
        $targetWsUrl = "{$worker->ws_protocol}://{$worker->ip_address}:{$worker->port}{$wsEndpointPath}";

        // Définir l'URL de callback pour que FastAPI puisse notifier Laravel
        $callbackUrl = $this->laravelAppUrl . route('api.workers.taskUpdate', [], false); // false pour URL relative au domaine

        $crawlPayload = [
            'url' => $site->url, // FastAPI attend 'urls': [string]
            'output_dir' => $outputDir ?? "./crawl_output_site_{$site->id}_worker_{$worker->worker_identifier}",
            'max_concurrency' => $maxConcurrency,
            'max_depth' => $maxDepth,
            'site_id_laravel' => $site->id, // Pour que FastAPI puisse le renvoyer
            'worker_identifier_laravel' => $worker->worker_identifier, // L'ID du worker FastAPI
            'callback_url' => $callbackUrl, // URL que FastAPI appellera à la fin
            'priority' => $site->priority?->value, // Envoyer la priorité
        ];
        $messageToSend = ['action' => 'start_crawl', 'payload' => $crawlPayload];

        Log::info("FastApiService (WebSocket): Connexion à {$targetWsUrl} pour Site ID {$site->id}");
        $client = null;
        try {
            $client = new WebSocketClient($targetWsUrl, ['timeout' => 10]); // Timeout de connexion de 10s
            Log::info("FastApiService (WebSocket): Envoi commande à {$targetWsUrl} pour Site ID {$site->id}: ", $messageToSend);
            $client->send(json_encode($messageToSend));

            // Attendre un accusé de réception (timeout géré par la bibliothèque, souvent 60s par défaut pour receive)
            $rawResponse = $client->receive(); // Potentiellement bloquant
            $receivedData = json_decode($rawResponse, true);
            Log::info("FastApiService (WebSocket): Réponse de {$targetWsUrl} pour Site ID {$site->id}: ", ['raw_response' => $rawResponse]);

            if ($receivedData && isset($receivedData['type']) && $receivedData['type'] === 'ack') {
                $site->status_api = SiteStatus::SUBMITTED_TO_API; // Tâche acceptée par FastAPI
                $site->fastapi_job_id = $receivedData['assignment_id'] ?? $receivedData['task_id'] ?? $site->fastapi_job_id; // Si FastAPI renvoie un ID de tâche pour ce crawl
                $site->last_sent_to_api_at = now();
                $site->last_api_response = 'Commande de crawl acceptée par FastAPI worker ' . $worker->name . '. En attente de callback.';
                $site->saveQuietly();
                // L'événement SiteStatusUpdated sera dispatché par le Job après cet appel

                return ['success' => true, 'message' => $receivedData['message'] ?? 'Commande acceptée.', 'fastapi_raw_response' => $rawResponse];
            } else {
                $responseMessage = 'Réponse inattendue ou erreur de FastAPI après envoi commande WebSocket.';
                if($receivedData && isset($receivedData['type']) && $receivedData['type'] === 'error') {
                    $responseMessage = $receivedData['message'] ?? $responseMessage;
                }
                Log::warning("FastApiService (WebSocket): Réponse non-ACK pour Site ID {$site->id}: " . $rawResponse);
                $site->status_api = SiteStatus::FAILED_API_SUBMISSION;
                $site->last_api_response = Str::limit($responseMessage . " Raw: " . $rawResponse, 500);
                $site->saveQuietly();
                $defaultResponse['message'] = $responseMessage;
            }
            $defaultResponse['fastapi_raw_response'] = $rawResponse;
            return $defaultResponse;

        } catch (ConnectionException $e) { /* ... (log et return $defaultResponse) ... */ }
        catch (Throwable $e) { /* ... (log et return $defaultResponse) ... */ }
        finally { $client?->close(); }
    }
}