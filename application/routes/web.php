<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DatasetWorkflowController;
use App\Http\Controllers\ImportController;
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
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/ml', [MlController::class, 'index'])->name('ml.index');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::get('/assistant/threads/{thread}', [AssistantController::class, 'show'])->name('assistant.threads.show');

    Route::middleware('role:admin,analyst')->group(function (): void {
        Route::post('/datasets', [DatasetController::class, 'store'])->middleware('throttle:upload')->name('datasets.store');
        Route::post('/datasets/{dataset}/preview', [DatasetWorkflowController::class, 'preview'])->name('datasets.preview');
        Route::post('/datasets/{dataset}/mapping', [DatasetWorkflowController::class, 'mapping'])->name('datasets.mapping');
        Route::post('/datasets/{dataset}/quality', [DatasetWorkflowController::class, 'quality'])->name('datasets.quality');
        Route::post('/datasets/{dataset}/commit', [DatasetWorkflowController::class, 'commit'])->name('datasets.commit');
        Route::post('/ml/train', [MlController::class, 'train'])->name('ml.train');
        Route::post('/assistant/threads', [AssistantController::class, 'store'])->name('assistant.store');
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('datasets.destroy');
        Route::delete('/assistant/threads/{thread}', [AssistantController::class, 'destroy'])->name('assistant.threads.destroy');
    });

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/ml/{modelId}/promote', [MlController::class, 'promote'])->name('ml.promote');
        Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    });
});
