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
    Route::post('workers/delete-all', [WorkerController::class, 'deleteAll']);
    Route::post('workers/restore-all', [WorkerController::class, 'restoreAll']);
    Route::post('workers/{worker}/deactivate', [WorkerController::class, 'deactivate']);
    Route::post('workers/{worker}/restore', [WorkerController::class, 'restore']);

    Route::apiResource('workers', WorkerController::class);

    Route::apiResource('rosters', RosterController::class)
        ->only(['index', 'show', 'store', 'destroy'])
        ->whereNumber('roster');
    Route::post('rosters/{roster}/regenerate', [RosterController::class, 'regenerate'])
        ->whereNumber('roster');
    Route::post('rosters/{roster}/export', [RosterController::class, 'export'])
        ->whereNumber('roster');
    Route::get('rosters/{roster}/export/{exportId}', [RosterController::class, 'exportStatus'])
        ->whereNumber('roster');
    Route::get('rosters/{roster}/export/{exportId}/download', [RosterController::class, 'exportDownload'])
        ->whereNumber('roster');
    Route::scopeBindings()->group(function (): void {
        Route::get('rosters/{roster}/assignments', [RosterAssignmentController::class, 'index']);
        Route::post('rosters/{roster}/assignments', [RosterAssignmentController::class, 'store']);
        Route::delete('rosters/{roster}/assignments/{assignment}', [RosterAssignmentController::class, 'destroy']);
    })->whereNumber('roster')->whereNumber('assignment');
});
