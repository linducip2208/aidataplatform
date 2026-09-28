<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Models\ChatThread;
use App\Models\Dataset;
use App\Services\AiEngineClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, AiEngineClient $engine): View
    {
        $stats = [
            'datasets' => Dataset::count(),
            'committed' => Dataset::where('status', DatasetStatus::Committed->value)->count(),
            'quarantined' => Dataset::where('status', DatasetStatus::Quarantined->value)->count(),
            'importing' => Dataset::whereIn('status', [
                DatasetStatus::Importing->value,
                DatasetStatus::Previewing->value,
                DatasetStatus::Uploaded->value,
            ])->count(),
        ];

        $engineHealth = null;
        $kpi = null;

        try {
            $engineHealth = $engine->health();
            $kpi = $engine->kpi(['granularity' => 'daily']);
        } catch (AiEngineException) {
            $kpi = null;
        }

        return view('dashboard', [
            'stats' => $stats,
            'engineHealth' => $engineHealth,
            'kpi' => $kpi,
            'recentDatasets' => Dataset::orderByDesc('created_at')->limit(8)->get(),
            'recentThreads' => ChatThread::where('user_id', $request->user()->getKey())
                ->orderByDesc('last_message_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
