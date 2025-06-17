<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; // Importer Schedule
use App\Models\Site;                      // Importer les modèles et Enums
use App\Models\User;
use App\Enums\SiteStatus;
use App\Events\SiteStatusUpdated;
use App\Jobs\SendSiteToFastApiJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// VOTRE TÂCHE PLANIFIÉE ICI
Schedule::call(function () {
    Log::info('Scheduler: Vérification des sites en attente d\'assignation de serveur.');

    // Verrou global pour éviter les exécutions parallèles de cette logique
    $lock = Cache::lock('assign_pending_sites_scheduler_lock', 60); // Verrou de 60 secondes

    if (!$lock->get()) {
        Log::info('Scheduler: Tâche d\'assignation déjà en cours ou récemment exécutée. Sortie.');
        return;
    }

    try {
        $sitesPendingAssignment = Site::where('status_api', SiteStatus::PENDING_ASSIGNMENT)
                                    ->orderBy('priority', 'desc') // Si vous avez la priorité
                                    ->orderBy('created_at', 'asc')
                                    ->get();

        if ($sitesPendingAssignment->isEmpty()) {
            Log::info('Scheduler: Aucun site en attente d\'assignation.');
            return;
        }

        Log::info("Scheduler: {$sitesPendingAssignment->count()} site(s) en attente d'assignation.");

        foreach ($sitesPendingAssignment as $siteToAssign) {
            // Tenter de trouver un utilisateur-serveur LIBRE pour CE site
            $availableServer = User::where('email', '!=', 'admin@admin.com') // Exclure l'admin
                                   ->whereNull('current_site_id_processing') // Qui est libre
                                   ->orderBy('id', 'asc') // Ou un autre critère pour choisir parmi les libres
                                   ->first();

            if ($availableServer) {
                Log::info("Scheduler: Site ID {$siteToAssign->id} sera assigné au serveur LIBRE ID {$availableServer->id} ({$availableServer->name}).");

                $siteToAssign->user_id = $availableServer->id;
                $siteToAssign->status_api = SiteStatus::PENDING_SUBMISSION;
                $siteToAssign->last_api_response = 'Assigné et mis en file d\'attente par le scheduler (serveur: ' . $availableServer->name . ')';
                $siteToAssign->save();
                \App\Events\SiteStatusUpdated::dispatch($siteToAssign->fresh());
                \App\Events\UserServerStatusChanged::dispatch($availableServer->fresh());

                // Le Job SendSiteToFastApiJob marquera le serveur comme "Pris" dans son handle()
                // Récupérer max_depth (valeur par défaut ou depuis un champ du site si vous en avez un)
                $defaultMaxDepth = $siteToAssign->default_max_depth ?? 0; // Suppose un champ, sinon 0
                SendSiteToFastApiJob::dispatch($siteToAssign, $defaultMaxDepth);

            } else {
                Log::info('Scheduler: Aucun serveur libre trouvé pour le moment. Les sites restants attendront la prochaine exécution.');
                break; // Arrêter d'essayer d'assigner si plus aucun serveur n'est libre pour cette passe
            }
        }
    } finally {
        $lock->release(); // Toujours libérer le verrou
    }
})->everyMinute() // Fréquence d'exécution
  ->name('assign_pending_sites_to_servers') // <--- AJOUT DU NOM UNIQUE ICI
  ->withoutOverlapping(10); // Empêche le chevauchement si la tâche dure plus de 10 minutes