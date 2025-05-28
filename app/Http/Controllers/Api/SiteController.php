<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\CrawlVersion;
use App\Models\Chunk;       // Utilisé dans showCrawlInfo
use App\Enums\SiteStatus;   // Enum pour le statut du Site
use App\Enums\CrawlVersionStatus; // Enum pour le statut de CrawlVersion
use App\Jobs\StartSiteCrawlJob; // Job pour initier le crawl d'un site
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SiteController extends Controller
{
    /**
     * Store a newly created site or sites in storage.
     * Accepts a list of URLs and associates them with a crawl version.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'urls' => 'required|array|min:1',
            'urls.*' => 'required|url|max:2048',
            'crawl_version_name' => 'required|string|max:255',
            'crawl_version_description' => 'nullable|string|max:1000', // Correspond à 'notes' dans CrawlVersion
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $urlsInput = $validatedData['urls'];
        $versionNameInput = $validatedData['crawl_version_name'];
        $versionDescriptionInput = $validatedData['crawl_version_description'] ?? null;

        // Récupérer ou créer la version de crawl
        // Utilise les noms de colonnes de votre modèle CrawlVersion: 'version_name' et 'notes'
        $crawlVersion = CrawlVersion::firstOrCreate(
            ['version_name' => $versionNameInput],
            [
                'notes' => $versionDescriptionInput,
                'status' => CrawlVersionStatus::PENDING, // CORRECT avec votre Enum
            ]
        );

        $createdSitesCount = 0;
        $updatedSitesToRecrawlCount = 0;
        $skippedSitesCount = 0;
        $processedSitesDetails = [];

        foreach ($urlsInput as $url) {
            // Normaliser l'URL
            $normalizedUrl = rtrim(strtolower($url), '/');
            if (!preg_match("~^(?:f|ht)tps?://~i", $normalizedUrl)) {
                $normalizedUrl = "http://" . $normalizedUrl;
            }

            // Vérifier si le site existe déjà pour cette version de crawl
            $existingSiteForThisVersion = Site::where('url', $normalizedUrl)
                                              ->where('crawl_version_id', $crawlVersion->id)
                                              ->first();

            if ($existingSiteForThisVersion) {
                // Option: Si vous souhaitez permettre de relancer un crawl pour un site déjà dans cette version
                // if ($existingSiteForThisVersion->status !== SiteStatus::PENDING && $existingSiteForThisVersion->status !== SiteStatus::PROCESSING) {
                //     $existingSiteForThisVersion->status = SiteStatus::PENDING;
                //     $existingSiteForThisVersion->last_crawled_at = null;
                //     $existingSiteForThisVersion->save();
                //     StartSiteCrawlJob::dispatch($existingSiteForThisVersion);
                //     $updatedSitesToRecrawlCount++;
                //     $processedSitesDetails[] = ['url' => $normalizedUrl, 'status' => 'recrawl_scheduled', 'site_id' => $existingSiteForThisVersion->id];
                //     Log::info("Site ID {$existingSiteForThisVersion->id} ({$normalizedUrl}) (crawl_version_id {$crawlVersion->id}) reprogrammé pour crawl.");
                // } else {
                    $skippedSitesCount++;
                    $processedSitesDetails[] = ['url' => $normalizedUrl, 'status' => 'skipped_exists_in_version_or_processing'];
                    Log::info("Site {$normalizedUrl} déjà existant et/ou en traitement pour la crawl_version_id {$crawlVersion->id}, ignoré.");
                // }
                continue;
            }

            // Si le site n'existe pas pour cette version, on le crée (ou on met à jour un site existant d'une autre version).
            // Avec la contrainte `url` unique sur la table `sites`, `firstOrNew` va soit trouver le site
            // par son URL, soit en préparer un nouveau.
            $site = Site::firstOrNew(['url' => $normalizedUrl]);
            $isNewSiteEntry = !$site->exists;

            // Associer/Réassocier à la version de crawl actuelle et réinitialiser le statut
            $site->crawl_version_id = $crawlVersion->id;
            $site->status = SiteStatus::PENDING_CRAWL; // Utilise l'Enum SiteStatus
            $site->last_crawled_at = null;
            $site->save();

            if ($isNewSiteEntry) {
                $createdSitesCount++;
                $processedSitesDetails[] = ['url' => $normalizedUrl, 'status' => 'created_and_queued', 'site_id' => $site->id];
                Log::info("Nouveau site ID {$site->id} ({$normalizedUrl}) créé, associé à la crawl_version_id {$crawlVersion->id} et mis en attente.");
            } else {
                // Le site existait (avec une autre crawl_version_id), on l'a mis à jour
                $updatedSitesToRecrawlCount++;
                $processedSitesDetails[] = ['url' => $normalizedUrl, 'status' => 'updated_and_queued', 'site_id' => $site->id];
                Log::info("Site existant ID {$site->id} ({$normalizedUrl}) mis à jour pour la crawl_version_id {$crawlVersion->id} et mis en attente.");
            }
            
            StartSiteCrawlJob::dispatch($site);
        }

        $messageParts = [];
        if ($createdSitesCount > 0) $messageParts[] = "{$createdSitesCount} nouveau(x) site(s) ajouté(s)";
        if ($updatedSitesToRecrawlCount > 0) $messageParts[] = "{$updatedSitesToRecrawlCount} site(s) existant(s) mis à jour pour cette version";
        if (count($messageParts) > 0) $messageParts[count($messageParts)-1] .= " et mis en file d'attente pour le crawling."; // Ajoute à la fin de la dernière partie
        if ($skippedSitesCount > 0) $messageParts[] = "{$skippedSitesCount} site(s) ignoré(s) (déjà traités ou en cours pour cette version).";

        return response()->json([
            'message' => empty($messageParts) ? "Aucune action effectuée sur les sites pour cette version." : implode(' ', $messageParts),
            'crawl_version' => $crawlVersion->only(['id', 'version_name', 'notes', 'status']), // Renvoyer des infos ciblées
            'sites_processed_details' => $processedSitesDetails,
        ], 201);
    }

    /**
     * Display the specified site's crawl information.
     *
     * @param  \App\Models\Site  $site
     * @return \Illuminate\Http\JsonResponse
     */
    public function showCrawlInfo(Site $site)
    {
        $site->loadCount(['pages', 'chunks']);

        $site->load([
            'crawlVersion:id,version_name,notes,status,started_at,completed_at', // Colonnes de votre modèle CrawlVersion
            'pages' => function ($query) {
                $query->select('id', 'site_id', 'crawl_version_id', 'url', 'status', 'last_crawled_at', 'sitemap_last_updated_at', 'content_hash') // Colonnes de votre modèle Page
                      ->withCount('chunks')
                      ->orderBy('created_at', 'desc')
                      ->limit(20);
            }
        ]);

        $pageStats = $site->pages()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Chunks directement liés au site (grâce à la dénormalisation site_id sur chunks)
        $chunkStats = $site->chunks() // Utilise la relation directe $site->chunks()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'site_info' => [
                'id' => $site->id,
                'url' => $site->url,
                'status' => $site->status, // Instance de SiteStatus
                'last_crawled_at' => $site->last_crawled_at,
                'crawl_version' => $site->crawlVersion,
                'total_pages_count' => $site->pages_count,
                'total_chunks_count' => $site->chunks_count,
            ],
            'page_samples' => $site->pages->map(function ($page) { // S'assurer que les statuts Enum sont des strings
                $pageArray = $page->toArray();
                $pageArray['status'] = $page->status->value; // Convertir l'enum en sa valeur string
                return $pageArray;
            }),
            'statistics' => [
                'pages_by_status' => $pageStats->mapWithKeys(fn($count, $status) => [$status->value => $count]), // Convertir les clés Enum en string
                'chunks_by_status' => $chunkStats->mapWithKeys(fn($count, $status) => [$status->value => $count]), // Convertir les clés Enum en string
            ]
        ]);
    }
}