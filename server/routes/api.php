<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RosterAssignmentController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('workers/reference-data', [WorkerController::class, 'referenceData']);
    Route::post('workers/import', [WorkerController::class, 'import']);
    Route::get('workers/import/sample', [WorkerController::class, 'importSample']);
    Route::get('workers/import/{importId}', [WorkerController::class, 'importStatus']);
    Route::post('workers/export', [WorkerController::class, 'export']);
    Route::get('workers/export/{exportId}', [WorkerController::class, 'exportStatus']);
    Route::get('workers/export/{exportId}/download', [WorkerController::class, 'exportDownload']);
    Route::delete('workers', [WorkerController::class, 'destroyAll']);

    Route::apiResource('workers', WorkerController::class);

    Route::post('rosters/generate', [RosterController::class, 'generate']);
    Route::get('rosters/generations/{generation}', [RosterController::class, 'showGeneration']);
    Route::apiResource('rosters', RosterController::class)->only(['index', 'show', 'destroy']);
    Route::post('rosters/{roster}/publish', [RosterController::class, 'publish']);
    Route::scopeBindings()->group(function (): void {
        Route::post('rosters/{roster}/assignments', [RosterAssignmentController::class, 'store']);
        Route::put('rosters/{roster}/assignments/{assignment}', [RosterAssignmentController::class, 'update']);
        Route::delete('rosters/{roster}/assignments/{assignment}', [RosterAssignmentController::class, 'destroy']);
    });
});
