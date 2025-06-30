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
    public function store(Request $request)
    {
        Log::info("--- DÉBUT STORE (AVEC PAYLOAD JSON) ---", $request->all());
    
        // 1. On valide que 'urls_json' est une chaîne JSON valide
        $validator = Validator::make($request->all(), [
            'urls_json' => 'required|json',
            'task_type' => 'required|string|in:crawl,check_existence,sitemap_crawl',
            'max_depth' => 'nullable|integer',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $validatedData = $validator->validated();
        $taskType = $validatedData['task_type'];
        $maxDepth = $validatedData['max_depth'] ?? null;
        
        // 2. On décode la chaîne JSON pour retrouver notre tableau d'URLs
        $urlsFromPayload = json_decode($validatedData['urls_json'], true);
    
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($urlsFromPayload)) {
            return response()->json(['message' => 'Le champ urls_json n\'est pas un tableau JSON valide.'], 422);
        }
    
        // 3. La validation de chaque URL se fait maintenant ici
        $validUrls = [];
        $invalidUrls = [];
        foreach ($urlsFromPayload as $url) {
            if (is_string($url) && Validator::make(['url' => $url], ['url' => 'url'])->passes()) {
                $validUrls[] = $url;
            } else {
                $invalidUrls[] = (string) $url; // Convertir en string pour les logs
            }
        }
    
        if (empty($validUrls)) {
            return response()->json(['message' => 'Aucune URL valide fournie dans le payload JSON.'], 422);
        }
        
        // LE RESTE DE LA LOGIQUE EST ABSOLUMENT IDENTIQUE À LA VERSION PRÉCÉDENTE
        
        $assignedCount = 0;
        $pendingAssignmentCount = 0;
        $skippedCount = 0;
        
        $availableWorkers = CrawlerWorker::where('status', WorkerStatus::ONLINE_IDLE)
                              ->whereNull('current_site_id_processing')
                              ->get();
    
        foreach ($validUrls as $url) {
            $normalizedUrl = rtrim(strtolower($url), '/');
            $site = Site::firstOrCreate(['url' => $normalizedUrl], ['priority' => SitePriority::NORMAL]);
    
            if ($site->wasRecentlyCreated || is_null($site->status_api)) {
                $workerToAssign = !$availableWorkers->isEmpty() ? $availableWorkers->shift() : null;
                if ($workerToAssign) {
                    $site->crawler_worker_id = $workerToAssign->id;
                    $site->status_api = SiteStatus::PENDING_SUBMISSION;
                    $assignedCount++;
                } else {
                    $site->status_api = SiteStatus::PENDING_ASSIGNMENT;
                    $pendingAssignmentCount++;
                }
                $site->priority = SitePriority::NORMAL;
                $site->task_type = $taskType;
                $site->max_depth = ($taskType === 'crawl') ? $maxDepth : null;
                $site->save();
                if (class_exists(SiteStatusUpdated::class)) SiteStatusUpdated::dispatch($site->fresh());
            } else {
                $skippedCount++;
            }
        }
        
        $messageParts = [];
        if ($assignedCount > 0) $messageParts[] = "$assignedCount site(s) assigné(s)";
        if ($pendingAssignmentCount > 0) $messageParts[] = "$pendingAssignmentCount en attente";
        if ($skippedCount > 0) $messageParts[] = "$skippedCount ignoré(s)";
        if (!empty($invalidUrls)) $messageParts[] = count($invalidUrls) . " URL(s) invalide(s)";
        
        $message = "Traitement terminé : " . implode(', ', $messageParts) . ".";
        Log::info($message);
        
        return response()->json(['message' => $message], 202);
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