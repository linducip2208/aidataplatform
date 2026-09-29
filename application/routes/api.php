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

Route::post('/login', [AuthController::class, 'store'])->name('api.login')->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::get('/health', [AnalyticsController::class, 'health'])->name('api.health');

    Route::get('/datasets', [DatasetController::class, 'index'])->name('api.datasets.index');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->name('api.datasets.show');

    // `GET .../quality` runs the engine's profile and writes `quality_score`,
    // `status` and `metadata` back to the row, so it is a write behind a safe
    // verb: a prefetch, a crawler or a link preview would trigger it. It stays
    // a GET because the documented contract says so, but it is gated to the
    // roles that may write, not to any authenticated user.
    Route::get('/datasets/{dataset}/quality', [DatasetController::class, 'quality'])
        ->middleware('role:admin,analyst')
        ->name('api.datasets.quality');

    Route::get('/import-jobs/{importJobId}', [ImportJobController::class, 'show'])->name('api.import-jobs.show');

    Route::get('/analytics/kpi', [AnalyticsController::class, 'kpi'])->name('api.analytics.kpi');
    Route::get('/analytics/trend', [AnalyticsController::class, 'trend'])->name('api.analytics.trend');
    Route::get('/analytics/rfm', [AnalyticsController::class, 'rfm'])->name('api.analytics.rfm');
    Route::get('/analytics/abc', [AnalyticsController::class, 'abc'])->name('api.analytics.abc');
    Route::get('/analytics/cohort', [AnalyticsController::class, 'cohort'])->name('api.analytics.cohort');
    Route::get('/analytics/branches', [AnalyticsController::class, 'branches'])->name('api.analytics.branches');
    Route::get('/analytics/finance', [AnalyticsController::class, 'finance'])->name('api.analytics.finance');
    Route::get('/analytics/kpi/definitions', [AnalyticsController::class, 'kpiDefinitions'])->name('api.analytics.kpi-definitions');
    Route::post('/analytics/kpi/definitions', [AnalyticsController::class, 'storeKpiDefinition'])->name('api.analytics.kpi-definitions.store');
    Route::get('/analytics/kpi/history', [AnalyticsController::class, 'kpiHistory'])->name('api.analytics.kpi-history');
    Route::post('/analytics/compare', [AnalyticsController::class, 'compare'])->name('api.analytics.compare');
    Route::post('/analytics/drilldown', [AnalyticsController::class, 'drilldown'])->name('api.analytics.drilldown');
    Route::post('/analytics/dashboards/resolve', [AnalyticsController::class, 'dashboard'])->name('api.analytics.dashboard');
    Route::post('/analytics/export', [AnalyticsController::class, 'export'])->name('api.analytics.export');

    Route::get('/ml/models', [MlController::class, 'index'])->name('api.ml.models');
    Route::get('/ml/models/{modelId}', [MlController::class, 'show'])->name('api.ml.models.show');
    Route::get('/ml/experiments', [MlController::class, 'experiments'])->name('api.ml.experiments');
    Route::post('/ml/experiments/{experimentId}/compare', [MlController::class, 'compareExperiment'])->name('api.ml.experiments.compare');
    Route::get('/ml/models/{modelId}/events', [MlController::class, 'events'])->name('api.ml.events');
    Route::get('/ml/models/{modelId}/detail', [MlController::class, 'detail'])->name('api.ml.detail');

    // An LLM call and a model training run are the two expensive endpoints, so
    // they carry a tighter limit than the blanket one on the `api` group.
    Route::post('/agent/chat', [AgentController::class, 'store'])->middleware('throttle:expensive')->name('api.agent.chat');
    Route::post('/rag/query', [RagController::class, 'query'])->middleware('throttle:expensive')->name('api.rag.query');

    Route::middleware('role:admin,analyst')->group(function (): void {
        Route::post('/datasets', [DatasetController::class, 'store'])->middleware('throttle:upload')->name('api.datasets.store');
        Route::post('/datasets/{dataset}/mapping', [DatasetController::class, 'mapping'])->name('api.datasets.mapping');
        Route::post('/datasets/{dataset}/commit', [DatasetController::class, 'commit'])->name('api.datasets.commit');
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('api.datasets.destroy');
        Route::post('/ml/train', [MlController::class, 'train'])->middleware('throttle:expensive')->name('api.ml.train');
        Route::post('/ml/experiments', [MlController::class, 'createExperiment'])->name('api.ml.experiments.store');
        Route::post('/ml/batch-predict', [MlController::class, 'batchPredict'])->name('api.ml.batch-predict');
    });

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/ml/models/{modelId}/promote', [MlController::class, 'promote'])->name('api.ml.models.promote');
        Route::post('/ml/experiments/{experimentId}/promote', [MlController::class, 'promoteExperiment'])->name('api.ml.experiments.promote');
        Route::post('/ml/models/{modelId}/rollback', [MlController::class, 'rollback'])->name('api.ml.rollback');
    });
});
