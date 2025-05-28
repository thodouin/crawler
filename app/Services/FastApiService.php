<?php

namespace App\Services;

use App\Models\Site; // Assurez-vous que le modèle Site est correctement importé
use App\Enums\SiteStatus; // Assurez-vous que l'Enum SiteStatus est correctement importé
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable; // Pour attraper toutes les erreurs/exceptions

class FastApiService
{
    protected string $apiUrlBase; // L'URL de base de l'API FastAPI
    protected ?string $apiKey;   // La clé API si votre FastAPI l'utilise

    public function __construct()
    {
        // rtrim pour s'assurer qu'il n'y a pas de slash en double à la fin de l'URL
        $this->apiUrlBase = rtrim(config('services.fastapi.url'), '/');
        $this->apiKey = config('services.fastapi.key');
    }

    /**
     * Soumet un site à l'API FastAPI pour le crawling.
     *
     * @param Site $site L'objet Site à envoyer.
     * @return bool Retourne true en cas de succès de la soumission, false sinon.
     */
    public function submitSiteForCrawling(Site $site, int $maxDepth = 0): bool
    {
        if (empty($this->apiUrlBase)) {
            Log::error("FastAPI URL non configurée. Site ID: {$site->id}");
            $site->fill([
                'status_api' => SiteStatus::FAILED_API_SUBMISSION, // Utilisez le bon Enum
                'last_api_response' => 'Erreur de configuration: FastAPI URL non définie.',
            ])->save();
            return false;
        }

        $endpoint = $this->apiUrlBase . '/crawl_single_url/'; // C'est le bon endpoint

        try {
            $formData = [
                'url' => $site->url,
                'max_depth' => $maxDepth,
            ];

            $request = Http::timeout(3600); // Augmenter légèrement le timeout général si les crawls peuvent être longs à initier

            if ($this->apiKey) {
                // Adaptez si FastAPI attend la clé API dans le formulaire ou ailleurs
                // $formData['api_key'] = $this->apiKey; // Si attendu dans le formulaire
                $request->withHeaders(['X-API-KEY' => $this->apiKey]); // Si attendu en header
            }
            
            Log::info("Envoi du site ID {$site->id} à FastAPI endpoint: " . $endpoint, ['form_data_to_send' => $formData]);
            $response = $request->asForm()->post($endpoint, $formData);

            if ($response->successful()) {
                $responseData = $response->json(); // FastAPI devrait toujours répondre avec du JSON
                Log::info("Réponse de FastAPI reçue pour site ID {$site->id}: ", $responseData);

                $finalLaravelStatus = SiteStatus::FAILED_PROCESSING_BY_API; // Statut par défaut si l'interprétation échoue
                $apiResponseMessage = $responseData['message'] ?? 'Réponse de FastAPI non standard ou crawl non concluant.';

                if (isset($responseData['request_status']) && $responseData['request_status'] === 'completed') {
                    if (isset($responseData['details']['total_pages_crawled_successfully']) && $responseData['details']['total_pages_crawled_successfully'] > 0) {
                        $finalLaravelStatus = SiteStatus::COMPLETED_BY_API; // Assurez-vous que ce cas existe dans votre Enum SiteStatus
                        $apiResponseMessage = $responseData['message'] ?? "Crawl terminé: {$responseData['details']['total_pages_crawled_successfully']} pages réussies.";
                    } elseif (isset($responseData['details']['total_pages_crawled_successfully']) && $responseData['details']['total_pages_crawled_successfully'] === 0 && (!isset($responseData['details']['total_pages_failed']) || $responseData['details']['total_pages_failed'] === 0)) {
                        $finalLaravelStatus = SiteStatus::COMPLETED_BY_API; // Ou un statut comme "COMPLETED_NO_PAGES"
                        $apiResponseMessage = $responseData['message'] ?? "Crawl terminé: Aucune page traitée avec succès.";
                    } else {
                        // Si request_status est "completed" mais 0 succès et des échecs, ou structure inattendue
                        $finalLaravelStatus = SiteStatus::FAILED_PROCESSING_BY_API; // Ou "COMPLETED_WITH_ERRORS"
                        $apiResponseMessage = $responseData['message'] ?? "Crawl terminé par FastAPI mais avec des problèmes ou 0 pages utiles.";
                    }
                } else {
                    // Si request_status n'est pas 'completed' ou est absent, mais que la requête HTTP était 2xx
                    Log::warning("Site ID {$site->id} - Réponse HTTP 2xx de FastAPI, mais statut de la requête FastAPI non 'completed'.", $responseData);
                    // Garder FAILED_PROCESSING_BY_API ou un statut intermédiaire comme SUBMITTED_TO_API si c'est une réponse d'acceptation de tâche
                    // Dans votre cas, FastAPI est synchrone, donc on s'attend à 'completed'.
                }

                $site->fill([
                    'status_api' => $finalLaravelStatus, // Utilisez le bon Enum
                    'fastapi_job_id' => $site->fastapi_job_id, // Conserver l'ancien ID si pas de nouveau, ou null
                    'last_sent_to_api_at' => now(), // Marque la fin de cette interaction
                    'last_api_response' => Str::limit("FastAPI: {$apiResponseMessage} | Détails: " . json_encode($responseData['details'] ?? ($responseData ?? [])), 1000),
                ])->save();

                Log::info("Site ID {$site->id} envoyé avec succès à FastAPI.", ['response' => $responseData]);
                return true;
            } else { // Réponse HTTP non-2xx (4xx, 5xx)
                $errorDetail = $response->json()['detail'] ?? $response->body(); // Tenter de parser JSON pour 'detail'
                $site->fill([
                    'status_api' => SiteStatus::FAILED_API_SUBMISSION,
                    'last_api_response' => "Échec soumission FastAPI ({$response->status()}): " . Str::limit($errorDetail, 250),
                ])->save();
                Log::error("Échec de l'envoi du site ID {$site->id} à FastAPI (HTTP {$response->status()}).", [
                    'form_data_sent' => $formData,
                    'response_body' => $response->body()
                ]);
                return false;
            }
        } catch (Throwable $e) { // Timeout de connexion, erreur DNS, etc.
            $site->fill([
                'status_api' => SiteStatus::FAILED_API_SUBMISSION,
                'last_api_response' => 'Exception communication API: ' . $e->getMessage(),
            ])->save();
            Log::critical("Exception lors de l'envoi du site ID {$site->id} à FastAPI.", [
                'error' => $e->getMessage(),
                'trace' => Str::limit($e->getTraceAsString(), 1000)
            ]);
            return false;
        }
    }
}