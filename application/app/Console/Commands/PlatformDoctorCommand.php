<?php

namespace App\Console\Commands;

use App\Support\PlatformHealth;
use Illuminate\Console\Command;

class PlatformDoctorCommand extends Command
{
    protected $signature = 'platform:doctor
                            {--json : Emit the report as JSON so a monitor can read it}';

    protected $description = 'Check that this deployment is sane: configuration, engine key agreement, database and storage';

    public function __construct(private readonly PlatformHealth $health)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->health->report();
        $status = $this->health->overallStatus($report);
        $exitCode = $status === PlatformHealth::DOWN ? self::FAILURE : self::SUCCESS;

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $this->health->toArray($report),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $exitCode;
        }

        $this->line(sprintf(
            '<comment>platform:doctor</comment>  app=%s  env=%s  driver=%s',
            (string) config('app.name'),
            (string) config('app.env'),
            (string) config('database.default'),
        ));

        $this->render($report);
        $this->summary($report, $status);

        return $exitCode;
    }

    /**
     * @param  array<string, array{status: string, detail: string, remedy: string|null, group: string}>  $report
     */
    private function render(array $report): void
    {
        $width = 0;

        foreach (array_keys($report) as $component) {
            $width = max($width, strlen((string) $component));
        }

        $group = null;

        foreach ($report as $component => $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                $this->newLine();
                $this->line('<comment>'.$group.'</comment>');
            }

            $this->line(sprintf(
                '  %s  %s  %s',
                $this->tag($check['status']),
                str_pad((string) $component, $width),
                $check['detail'],
            ));

            if ($check['status'] !== PlatformHealth::OK && is_string($check['remedy'])) {
                $this->line('        remedy: '.$check['remedy']);
            }
        }
    }

    /**
     * @param  array<string, array{status: string, detail: string, remedy: string|null, group: string}>  $report
     */
    private function summary(array $report, string $status): void
    {
        $counts = $this->health->counts($report);

        $this->newLine();
        $this->line(sprintf('%d ok, %d warn, %d fail', $counts['ok'], $counts['warn'], $counts['down']));

        match ($status) {
            PlatformHealth::DOWN => $this->line('<fg=red>The deployment is not sane. Fix every fail above before serving traffic.</>'),
            PlatformHealth::WARN => $this->line('<comment>Usable with warnings. Review the warn lines above.</>'),
            default => $this->line('<info>Everything checks out.</>'),
        };
    }

    private function tag(string $status): string
    {
        return match ($status) {
            PlatformHealth::DOWN => '<fg=red>FAIL</>',
            PlatformHealth::WARN => '<comment>WARN</>',
            default => '<info>PASS</>',
        };
    }
}
