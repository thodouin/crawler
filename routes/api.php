<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WorkerController; // Créer ce contrôleur

// Sécurisez ces routes avec un middleware de token API si nécessaire pour la production
Route::post('/workers/register', [WorkerController::class, 'register'])->name('api.workers.register');

Route::post('/workers/heartbeat', [WorkerController::class, 'heartbeat'])->name('api.workers.heartbeat');

Route::post('/workers/task-update', [WorkerController::class, 'taskUpdate'])->name('api.workers.taskUpdate'); // Quand un crawl est fini/échoué sur FastAPI