<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\GeneratedReport;
use App\Models\User;

/**
 * Scheduled + on-demand AI report generation.
 *
 * One engine call (`ai/report`, JSON) persisted verbatim as a
 * `generated_reports` row, so history survives engine outages and template
 * edits. Failures propagate as `AiEngineException` — the scheduler logs
 * them, the web flow flashes them; nothing stores a half-report.
 */
class ReportGenerationService
{
    public function __construct(private readonly AiEngineClient $engine) {}

    public function generate(string $period = 'weekly', ?User $user = null): GeneratedReport
    {
        $payload = $this->engine->report($period);

        $report = GeneratedReport::query()->create([
            'period' => $period,
            'status' => 'generated',
            'payload' => $payload,
            'created_by' => $user?->getKey(),
        ]);

        AuditLog::record('report.generated', 'generated_report', $report->getKey(), [
            'period' => $period,
            'by' => $user?->email,
        ]);

        return $report;
    }
}
