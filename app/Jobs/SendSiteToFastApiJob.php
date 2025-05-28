<?php

namespace App\Jobs;

use App\Models\Site; // Assurez-vous que le modèle Site est correctement importé
use App\Services\FastApiService; // Assurez-vous que le service est correctement importé
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; // Implémentez ShouldQueue pour le traitement asynchrone
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSiteToFastApiJob implements ShouldQueue // Important pour le traitement en arrière-plan
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Le nombre de fois où le job peut être tenté.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de retenter le job.
     * Peut être un tableau pour des backoffs exponentiels/personnalisés.
     * @var int|array
     */
    public $backoff = [60, 180, 600]; // Attendre 1 min, puis 3 min, puis 10 min

    /**
     * L'instance du site à traiter.
     *
     * @var \App\Models\Site
     */
    public Site $site;

    /**
     * Create a new job instance.
     *
     * @param Site $site L'objet Site à envoyer à FastAPI
     */
    public function __construct(Site $site)
    {
        $this->site = $site;
    }

    /**
     * Execute the job.
     *
     * @param  \App\Services\FastApiService  $fastApiService
     * @return void
     */
    public function handle(FastApiService $fastApiService): void
    {
        Log::info("Job: Tentative d'envoi du site ID {$this->site->id} ({$this->site->url}) à FastAPI.");

        // Appel du service pour effectuer la soumission réelle
        $submissionSuccessful = $fastApiService->submitSiteForCrawling($this->site);

        if (!$submissionSuccessful) {
            // Le service FastApiService met déjà à jour le statut du site et logue l'erreur.
            // Le système de file d'attente (avec $tries et $backoff) gérera les nouvelles tentatives.
            Log::warning("Job: Échec de l'envoi du site ID {$this->site->id} à FastAPI. Le job sera retenté si le nombre de tentatives n'est pas dépassé.");
            
            // Si vous voulez un comportement de "release" plus spécifique basé sur le type d'erreur,
            // vous pouvez le faire ici, mais souvent le backoff automatique suffit.
            // Par exemple, si c'est une erreur 4xx de FastAPI, pas la peine de retenter indéfiniment.
            // $this->release(300); // Libère le job pour qu'il soit retenté après 300 secondes.
        } else {
            Log::info("Job: Site ID {$this->site->id} envoyé avec succès à FastAPI.");
        }
    }

    /**
     * Gérer un échec de job.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void // BON TYPE HINT
    {
        // Ce code est exécuté si le job échoue après toutes ses tentatives.
        Log::error("Job: Échec définitif de l'envoi du site ID {$this->site->id} à FastAPI après {$this->attempts()} tentatives.", [
            'exception_message' => $exception->getMessage(),
            'site_url' => $this->site->url,
            // Vous devriez ajouter la trace ici pour comprendre POURQUOI le job a échoué à l'origine
            'exception_trace' => \Illuminate\Support\Str::limit($exception->getTraceAsString(), 2000) 
        ]);

        // Mettre à jour le statut du site pour refléter l'échec définitif
        // Assurez-vous que l'Enum et le cas existent.
        // Si 'SiteStatus' est dans le namespace App\Enums, le use statement du service suffit
        $this->site->update([
            'status_api' => \App\Enums\SiteStatus::FAILED_API_SUBMISSION, // Assurez-vous que ce cas existe et que SiteStatus est le bon Enum
            'last_api_response' => 'Échec du Job après toutes les tentatives: ' . \Illuminate\Support\Str::limit($exception->getMessage(), 255),
        ]);
    }
}