<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Services\AiCostService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiCostController extends Controller
{
    public function __construct(private readonly AiCostService $costs) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $summary = $this->costs->summary((int) ($validated['days'] ?? 30));
            $engineAvailable = true;
        } catch (AiEngineException $exception) {
            $summary = [];
            $engineAvailable = false;
        }

        return view('aicost.index', [
            'summary' => $summary,
            'totals' => (array) ($summary['totals'] ?? []),
            'byModel' => (array) ($summary['by_model'] ?? []),
            'byDay' => (array) ($summary['by_day'] ?? []),
            'days' => (int) ($summary['days'] ?? ($validated['days'] ?? 30)),
            'filters' => $request->only(['days']),
            'engineAvailable' => $engineAvailable,
        ]);
    }
}
