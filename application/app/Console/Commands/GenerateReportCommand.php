<?php

namespace App\Console\Commands;

use App\Services\ReportGenerationService;
use Illuminate\Console\Command;

class GenerateReportCommand extends Command
{
    protected $signature = 'report:generate
        {--period=weekly : Report period (daily, weekly, monthly)}';

    protected $description = 'Generate the AI executive report and store it in history';

    public function handle(ReportGenerationService $service): int
    {
        $period = (string) $this->option('period');

        if (! in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            $this->error("Unknown period [{$period}]. Use daily, weekly, or monthly.");

            return self::INVALID;
        }

        $report = $service->generate($period);

        $this->info("Report #{$report->getKey()} ({$period}) stored.");

        return self::SUCCESS;
    }
}
