<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\CrawlerWorker; // <-- IMPORTANT
use App\Enums\SiteStatus;
use App\Enums\SitePriority;
use App\Enums\WorkerStatus; // <-- IMPORTANT
use App\Events\SiteStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    /**
     * Store one or more new sites AND try to assign a task immediately.
     */
    public function store(Request $request)
    {
        Log::info("--- DÉBUT DE L'EXÉCUTION DE LA MÉTHODE STORE (VERSION FINALE AVEC ASSIGNATION) ---", $request->all());

        $validator = Validator::make($request->all(), [
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|url|max:2048',
            // On valide que le task_type est un slug qui existe dans la table task_types et est actif
            'task_type' => [
                'required',
                'string',
                Rule::exists('task_types', 'slug')->where(function ($query) {
                    return $query->where('is_active', true);
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $urlsInput = $validatedData['urls'];
        $taskType = $validatedData['task_type'];

        // --- LOGIQUE DE DISTRIBUTION AMÉLIORÉE ---

        // 1. On récupère TOUS les workers libres en une seule fois AVANT la boucle.
        $availableWorkers = CrawlerWorker::where('status', WorkerStatus::ONLINE_IDLE)
            ->whereNull('current_site_id_processing')
            ->orderBy('last_heartbeat_at', 'asc')
            ->get(); // On utilise get() pour avoir une collection

        $workerCount = $availableWorkers->count();
        Log::info("Trouvé {$workerCount} worker(s) libre(s) pour la distribution des tâches.");
        $workerIndex = 0; // On va utiliser cet index pour faire du "round-robin"

        $assignedCount = 0;
        $pendingAssignmentCount = 0;
        $skippedCount = 0;

        foreach ($urlsInput as $url) {
        $normalizedUrl = rtrim(strtolower($url), '/');
        $site = Site::firstOrCreate(
        ['url' => $normalizedUrl],
        ['priority' => SitePriority::NORMAL]
        );

        if ($site->wasRecentlyCreated || is_null($site->status_api)) {

        // 2. On vérifie si on a des workers disponibles dans notre liste
        if ($workerCount > 0) {
        // 3. On choisit le prochain worker dans la liste (distribution "round-robin")
        $workerToAssign = $availableWorkers[$workerIndex];

        // Assignation de la tâche
        $site->crawler_worker_id = $workerToAssign->id;
        $site->status_api = SiteStatus::PENDING_SUBMISSION;
        $site->task_type = $taskType;
        $site->last_api_response = 'Tâche (' . $taskType . ') assignée via API à: ' . $workerToAssign->name;
        $assignedCount++;
        Log::info("Site ID {$site->id} assigné au Worker ID {$workerToAssign->id} pour la tâche '{$taskType}'.");

        // 4. On passe au worker suivant pour la prochaine itération.
        // Si on arrive au bout de la liste, on recommence au début.
        $workerIndex = ($workerIndex + 1) % $workerCount;

        } else {
        // S'il n'y avait AUCUN worker libre au départ, on met tout en attente
        $site->status_api = SiteStatus::PENDING_ASSIGNMENT;
        $site->task_type = $taskType;
        $site->last_api_response = 'En attente d\'un Worker FastAPI disponible pour la tâche: ' . $taskType;
        $pendingAssignmentCount++;
        Log::info("Site ID {$site->id}: Aucun worker libre trouvé initialement. Mis en PENDING_ASSIGNMENT.");
        }

        $site->save();

        if (class_exists(SiteStatusUpdated::class)) {
        SiteStatusUpdated::dispatch($site->fresh());
        }

        } else {
            $skippedCount++;
            Log::info("Site ID {$site->id} ({$normalizedUrl}) déjà en cours de traitement (statut: {$site->status_api?->value}), ignoré.");
        }
        }

        $messageParts = [];
        if ($assignedCount > 0) $messageParts[] = "{$assignedCount} site(s) directement assignés à un worker.";
        if ($pendingAssignmentCount > 0) $messageParts[] = "{$pendingAssignmentCount} site(s) mis en attente d'un worker libre.";
        if ($skippedCount > 0) $messageParts[] = "{$skippedCount} site(s) ignorés car déjà en cours de traitement.";

        return response()->json([
            'message' => empty($messageParts) ? "Aucune action effectuée." : implode(' ', $messageParts),
        ], 201);
    }

    /**
     * Display the specified site's information.
     */
    public function show(Site $site)
    {
        $site->load('crawlerWorker:id,name,status');
        
        return response()->json([
            'site_info' => $site->toArray(), // Simplifié pour tout renvoyer
            'assigned_worker' => $site->crawlerWorker,
        ]);
    }
}