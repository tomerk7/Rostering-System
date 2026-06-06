<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\RosterAssignmentController;
use App\Http\Controllers\Api\RosterController;
use App\Http\Controllers\Api\WorkerCsvController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::get('reference-data', [ReferenceDataController::class, 'index']);

    Route::post('workers/import', [WorkerCsvController::class, 'import']);
    Route::get('workers/export', [WorkerCsvController::class, 'export']);

    Route::apiResource('workers', WorkerController::class);

    Route::post('rosters/preview', [RosterController::class, 'preview']);
    Route::apiResource('rosters', RosterController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('rosters/{roster}/publish', [RosterController::class, 'publish']);
    Route::scopeBindings()->group(function (): void {
        Route::post('rosters/{roster}/assignments', [RosterAssignmentController::class, 'store']);
        Route::put('rosters/{roster}/assignments/{assignment}', [RosterAssignmentController::class, 'update']);
        Route::delete('rosters/{roster}/assignments/{assignment}', [RosterAssignmentController::class, 'destroy']);
    });
});