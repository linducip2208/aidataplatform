<?php

use App\Http\Controllers\Admin\AiProviderController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AiCostController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetWorkflowController;
use App\Http\Controllers\DecisionCenterController;
use App\Http\Controllers\GlossaryController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MlController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/login', [AuthController::class, 'create'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'store'])->name('login.store')->middleware(['guest', 'throttle:login']);
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated UI
|--------------------------------------------------------------------------
*/

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile/password', [ProfileController::class, 'edit'])->name('password.edit');
    Route::put('/profile/password', [ProfileController::class, 'update'])->name('password.update');

    Route::get('/datasets', [DatasetController::class, 'index'])->name('datasets.index');
    Route::get('/datasets/create', [DatasetController::class, 'create'])->name('datasets.create');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->name('datasets.show');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/{dataset}', [ImportController::class, 'show'])->name('imports.show');

    Route::get('/quality', [QualityController::class, 'index'])->name('quality.index');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
    Route::get('/glossary', [GlossaryController::class, 'index'])->name('glossary.index');
    Route::get('/ai/usage', [AiCostController::class, 'index'])->name('ai.usage');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/ml', [MlController::class, 'index'])->name('ml.index');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::get('/decisions', [DecisionCenterController::class, 'index'])->name('decisions.index');
    Route::get('/decisions/{id}', [DecisionCenterController::class, 'show'])->whereNumber('id')->name('decisions.show');
    Route::get('/assistant/threads/{thread}', [AssistantController::class, 'show'])->name('assistant.threads.show');

    Route::middleware('role:admin,analyst')->group(function (): void {
        Route::post('/datasets', [DatasetController::class, 'store'])->middleware('throttle:upload')->name('datasets.store');
        Route::post('/datasets/{dataset}/preview', [DatasetWorkflowController::class, 'preview'])->name('datasets.preview');
        Route::post('/datasets/{dataset}/mapping', [DatasetWorkflowController::class, 'mapping'])->name('datasets.mapping');
        Route::post('/datasets/{dataset}/quality', [DatasetWorkflowController::class, 'quality'])->name('datasets.quality');
        Route::post('/datasets/{dataset}/commit', [DatasetWorkflowController::class, 'commit'])->name('datasets.commit');
        Route::post('/ml/train', [MlController::class, 'train'])->name('ml.train');
        Route::post('/ml/experiments', [MlController::class, 'createExperiment'])->name('ml.experiments.store');
        Route::post('/ml/batch-predict', [MlController::class, 'batchPredict'])->name('ml.batch-predict');
        Route::post('/assistant/threads', [AssistantController::class, 'store'])->name('assistant.store');
        Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
        Route::post('/decisions/recommend', [DecisionCenterController::class, 'recommend'])->name('decisions.recommend');
        Route::post('/decisions/scenarios/run', [DecisionCenterController::class, 'runScenario'])->name('decisions.scenarios.run');
        Route::post('/decisions/{id}/audit', [DecisionCenterController::class, 'audit'])->whereNumber('id')->name('decisions.audit');
        Route::post('/alerts/{id}/ack', [AlertController::class, 'acknowledge'])->whereNumber('id')->name('alerts.ack');
        Route::post('/alerts/rules', [AlertController::class, 'storeRule'])->name('alerts.rules.store');
        Route::post('/alerts/rules/{id}/toggle', [AlertController::class, 'toggleRule'])->whereNumber('id')->name('alerts.rules.toggle');
        Route::post('/knowledge', [KnowledgeBaseController::class, 'store'])->name('knowledge.store');
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('datasets.destroy');
        Route::delete('/assistant/threads/{thread}', [AssistantController::class, 'destroy'])->name('assistant.threads.destroy');
    });

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/ml/{modelId}/promote', [MlController::class, 'promote'])->name('ml.promote');
        Route::post('/ml/experiments/{experimentId}/promote', [MlController::class, 'promoteExperiment'])->name('ml.experiments.promote');
        Route::post('/ml/{modelId}/rollback', [MlController::class, 'rollback'])->name('ml.rollback');
        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/admin/organization', [OrganizationController::class, 'edit'])->name('admin.organization.edit');
        Route::put('/admin/organization', [OrganizationController::class, 'update'])->name('admin.organization.update');
        Route::get('/admin/providers', [AiProviderController::class, 'index'])->name('admin.providers.index');
        Route::get('/admin/providers/create', [AiProviderController::class, 'create'])->name('admin.providers.create');
        Route::post('/admin/providers', [AiProviderController::class, 'store'])->name('admin.providers.store');
        Route::get('/admin/providers/{provider}/edit', [AiProviderController::class, 'edit'])->name('admin.providers.edit');
        Route::put('/admin/providers/{provider}', [AiProviderController::class, 'update'])->name('admin.providers.update');
        Route::delete('/admin/providers/{provider}', [AiProviderController::class, 'destroy'])->name('admin.providers.destroy');
        Route::post('/admin/providers/{provider}/toggle', [AiProviderController::class, 'toggle'])->name('admin.providers.toggle');
        Route::post('/admin/providers/{provider}/test', [AiProviderController::class, 'test'])->name('admin.providers.test');
        Route::post('/admin/providers/{provider}/publish', [AiProviderController::class, 'publish'])->name('admin.providers.publish');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    });
});
