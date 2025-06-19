<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WorkerController; // Créer ce contrôleur
use App\Http\Controllers\Api\V1\WorkerApiController;

// Sécurisez ces routes avec un middleware de token API si nécessaire pour la production
Route::post('/workers/register', [WorkerController::class, 'register'])->name('api.workers.register');

Route::post('/workers/heartbeat', [WorkerController::class, 'heartbeat'])->name('api.workers.heartbeat');

Route::post('/workers/task-update', [WorkerController::class, 'taskUpdate'])->name('api.workers.taskUpdate'); // Quand un crawl est fini/échoué sur FastAPI

Route::prefix('v1')->group(function () {
    
    // Endpoint pour obtenir une tâche de vérification d'existence
    Route::post('/worker/get-existence-check-task', [WorkerApiController::class, 'getExistenceCheckTask']);
    
    // Endpoint pour obtenir une tâche de crawl complet
    Route::post('/worker/get-crawl-task', [WorkerApiController::class, 'getCrawlTask']);
});