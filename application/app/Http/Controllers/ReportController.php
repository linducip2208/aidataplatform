<?php

namespace App\Http\Controllers;

use App\Exceptions\AiEngineException;
use App\Services\AiEngineClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    private const PERIODS = ['daily', 'weekly', 'monthly'];

    public function __invoke(Request $request, AiEngineClient $engine): View
    {
        $request->validate([
            'period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
        ], [], ['period' => 'periode']);

        $period = (string) $request->query('period', 'weekly');
        $report = [];
        $engineAvailable = true;

        try {
            $report = $engine->report($period);
        } catch (AiEngineException) {
            $engineAvailable = false;
        }

        return view('reports.index', [
            'report' => $report,
            'periods' => self::PERIODS,
            'period' => $period,
            'engineAvailable' => $engineAvailable,
        ]);
    }

    public function index(Request $request, AiEngineClient $engine): View
    {
        return $this->__invoke($request, $engine);
    }
}
