<?php

namespace App\Http\Controllers;

use App\Enums\QualityVerdict;
use App\Models\Dataset;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QualityController extends Controller
{
    public function index(Request $request): View
    {
        $datasets = Dataset::query()
            ->whereNotNull('quality_score')
            ->ofType($request->query('dataset_type'))
            ->when(
                $request->filled('verdict'),
                fn ($query) => $query->where('quality_verdict', $request->query('verdict')),
            )
            ->orderByDesc('quality_checked_at')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        $breakdown = [
            'pass' => Dataset::where('quality_verdict', QualityVerdict::Pass->value)->count(),
            'quarantine' => Dataset::where('quality_verdict', QualityVerdict::Quarantine->value)->count(),
            'unscored' => Dataset::whereNull('quality_score')->count(),
        ];

        return view('quality.index', [
            'datasets' => $datasets,
            'breakdown' => $breakdown,
            'threshold' => config('ai_engine.quality_threshold'),
            'verdicts' => QualityVerdict::cases(),
            'filters' => $request->only(['dataset_type', 'verdict']),
        ]);
    }
}
