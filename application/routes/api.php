<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\ImportJobController;
use App\Http\Controllers\Api\MlController;
use App\Http\Controllers\Api\RagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API (Sanctum bearer tokens)
|--------------------------------------------------------------------------
|
| This file is loaded with the `api` middleware group (stateless, no CSRF), so
| the same controllers back both the Blade UI and external clients.
|
*/

Route::post('/login', [AuthController::class, 'store'])->name('api.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::get('/health', [AnalyticsController::class, 'health'])->name('api.health');

    Route::get('/datasets', [DatasetController::class, 'index'])->name('api.datasets.index');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->name('api.datasets.show');
    Route::get('/datasets/{dataset}/quality', [DatasetController::class, 'quality'])->name('api.datasets.quality');

    Route::get('/import-jobs/{importJobId}', [ImportJobController::class, 'show'])->name('api.import-jobs.show');

    Route::get('/analytics/kpi', [AnalyticsController::class, 'kpi'])->name('api.analytics.kpi');
    Route::get('/analytics/trend', [AnalyticsController::class, 'trend'])->name('api.analytics.trend');
    Route::get('/analytics/rfm', [AnalyticsController::class, 'rfm'])->name('api.analytics.rfm');
    Route::get('/analytics/abc', [AnalyticsController::class, 'abc'])->name('api.analytics.abc');
    Route::get('/analytics/cohort', [AnalyticsController::class, 'cohort'])->name('api.analytics.cohort');
    Route::get('/analytics/branches', [AnalyticsController::class, 'branches'])->name('api.analytics.branches');
    Route::get('/analytics/finance', [AnalyticsController::class, 'finance'])->name('api.analytics.finance');

    Route::get('/ml/models', [MlController::class, 'index'])->name('api.ml.models');
    Route::get('/ml/models/{modelId}', [MlController::class, 'show'])->name('api.ml.models.show');

    Route::post('/agent/chat', [AgentController::class, 'store'])->name('api.agent.chat');
    Route::post('/rag/query', [RagController::class, 'query'])->name('api.rag.query');

    Route::middleware('role:admin,analyst')->group(function (): void {
        Route::post('/datasets', [DatasetController::class, 'store'])->name('api.datasets.store');
        Route::post('/datasets/{dataset}/mapping', [DatasetController::class, 'mapping'])->name('api.datasets.mapping');
        Route::post('/datasets/{dataset}/commit', [DatasetController::class, 'commit'])->name('api.datasets.commit');
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('api.datasets.destroy');
        Route::post('/ml/train', [MlController::class, 'train'])->name('api.ml.train');
    });

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/ml/models/{modelId}/promote', [MlController::class, 'promote'])->name('api.ml.models.promote');
    });
});
