<?php

use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\WorkerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('reference-data', [ReferenceDataController::class, 'index']);
Route::apiResource('workers', WorkerController::class);
