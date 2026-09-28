<?php

namespace App\Console\Commands;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Jobs\RefreshQualityScoreJob;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\PlatformHealth;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SyncQualityCommand extends Command
{
    protected $signature = 'sync:quality
                            {--dataset= : Re-check only this dataset UUID}
                            {--limit=50 : Maximum datasets to re-check in one run}
                            {--days=30 : Re-check datasets whose last quality run is older than this}
                            {--force : Ignore the staleness window and re-check every committed dataset}
                            {--dry-run : List what would be re-checked without calling the engine}
                            {--queue : Dispatch the re-checks to a worker instead of running them inline}';

    protected $description = 'Re-run the engine quality check for committed datasets whose score is missing or stale';

    public function __construct(
        private readonly DatasetIngestionService $ingestion,
        private readonly PlatformHealth $health,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->preflight()) {
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $queued = (bool) $this->option('queue');

        try {
            $target = $this->resolveTarget();
            $datasets = $target instanceof Dataset ? collect([$target]) : $this->stale($days, $limit);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('cannot read the datasets table: '.$this->reason($exception->getMessage()));
            $this->line('  remedy: run `php artisan migrate --force`');

            return self::FAILURE;
        }

        if ($datasets->isEmpty()) {
            $this->info('Every committed dataset has a fresh quality score. Nothing to re-check.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $quarantined = 0;
        $dispatched = 0;
        $failed = 0;

        foreach ($datasets as $dataset) {
            $staleFor = $this->staleness($dataset, $days);

            if ($dryRun) {
                $this->line(sprintf(
                    '  <comment>dry-run</comment>  %s  %s  %s',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $staleFor,
                ));

                continue;
            }

            if ($queued) {
                try {
                    RefreshQualityScoreJob::dispatch($dataset->uuid);
                } catch (Throwable $exception) {
                    $failed++;

                    Log::error('sync.quality_dispatch_failed', [
                        'dataset_uuid' => $dataset->uuid,
                        'error' => $exception->getMessage(),
                    ]);

                    $this->line(sprintf(
                        '  <fg=red>ERROR </>  %s  %s  could not dispatch: %s',
                        $dataset->uuid,
                        $this->truncate((string) $dataset->name),
                        $this->reason($exception->getMessage()),
                    ));

                    continue;
                }

                $dispatched++;

                $this->line(sprintf(
                    '  <info>QUEUED</>  %s  %s  %s',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $staleFor,
                ));

                continue;
            }

            try {
                $outcome = (new RefreshQualityScoreJob($dataset->uuid))->refresh($this->ingestion, $dataset);
            } catch (AiEngineException $exception) {
                $failed++;

                Log::warning('sync.quality_failed', [
                    'dataset_uuid' => $dataset->uuid,
                    'import_job_id' => $dataset->import_job_id,
                    'upstream_status' => $exception->upstreamStatus(),
                    'error' => $exception->getMessage(),
                ]);

                $this->line(sprintf(
                    '  <fg=red>ERROR </>  %s  %s  %s',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $this->reason($exception->getMessage()),
                ));

                continue;
            } catch (Throwable $exception) {
                $failed++;

                Log::error('sync.quality_failed', [
                    'dataset_uuid' => $dataset->uuid,
                    'error' => $exception->getMessage(),
                ]);

                $this->line(sprintf(
                    '  <fg=red>ERROR </>  %s  %s  %s',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $this->reason($exception->getMessage()),
                ));

                continue;
            }

            $quarantined += $outcome['status'] === DatasetStatus::Quarantined->value ? 1 : 0;
            $refreshed++;

            $this->line(sprintf(
                '  %s  %s  %s  score %s  verdict %s  status %s',
                $outcome['verdict'] === 'pass' ? '<info>PASSED</>' : '<fg=yellow>QUARAN</>',
                $dataset->uuid,
                $this->truncate((string) $dataset->name),
                number_format($outcome['score'] * 100, 1).'%',
                $outcome['verdict'],
                $outcome['status'],
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Quality pass: %d candidate(s), %d refreshed, %d quarantined, %d dispatched, %d failed.%s',
            $datasets->count(),
            $refreshed,
            $quarantined,
            $dispatched,
            $failed,
            $this->backlogNote($limit, $days),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The quality endpoint is on the engine, so a broken configuration is
     * reported once with the remedy `platform:doctor` gives rather than once
     * per dataset.
     */
    private function preflight(): bool
    {
        $checks = $this->health->preflight();

        foreach ($checks as $component => $check) {
            if ($check['status'] === PlatformHealth::WARN) {
                $this->line('  <comment>WARN</comment>  '.$component.'  '.$check['detail']);
            }
        }

        foreach ($checks as $component => $check) {
            if ($check['status'] === PlatformHealth::DOWN) {
                $this->error('Cannot reach the AI engine ['.$component.']: '.$check['detail']);

                if (is_string($check['remedy'])) {
                    $this->line('  remedy: '.$check['remedy']);
                }

                return false;
            }
        }

        return true;
    }

    private function resolveTarget(): ?Dataset
    {
        $uuid = $this->option('dataset');

        if (! is_string($uuid) || trim($uuid) === '') {
            return null;
        }

        $uuid = trim($uuid);
        $dataset = Dataset::query()->where('uuid', $uuid)->first();

        if ($dataset === null) {
            throw new InvalidArgumentException('No dataset with uuid "'.$uuid.'".');
        }

        return $dataset;
    }

    /**
     * Never checked first, then the oldest check, so a truncated run always
     * clears the least trustworthy scores first.
     *
     * @return Collection<int, Dataset>
     */
    private function stale(int $days, int $limit): Collection
    {
        return $this->query($days)
            ->orderByRaw('case when quality_checked_at is null then 0 else 1 end')
            ->orderBy('quality_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** @return Builder<Dataset> */
    private function query(int $days): Builder
    {
        $query = Dataset::query()->where('status', DatasetStatus::Committed->value);

        if ($this->option('force')) {
            return $query;
        }

        $cutoff = Carbon::now()->subDays($days);

        return $query->where(function (Builder $inner) use ($cutoff): void {
            $inner->whereNull('quality_score')
                ->orWhereNull('quality_checked_at')
                ->orWhere('quality_checked_at', '<', $cutoff);
        });
    }

    private function staleness(Dataset $dataset, int $days): string
    {
        $checked = $dataset->quality_checked_at;

        if ($checked === null) {
            return 'never checked';
        }

        if ($dataset->quality_score === null) {
            return 'no score recorded, last checked '.$checked->diffForHumans();
        }

        $age = (int) round(Carbon::now()->diffInDays($checked, absolute: true));

        return $this->option('force')
            ? 'last checked '.$age.' day(s) ago, --force ignores the '.$days.' day window'
            : 'last checked '.$age.' day(s) ago, window is '.$days.' day(s)';
    }

    private function backlogNote(int $limit, int $days): string
    {
        if ($this->option('dataset') !== null) {
            return '';
        }

        $pending = $this->query($days)->count();

        return $pending > $limit ? ' '.$pending.' still stale; raise --limit or run again.' : '';
    }

    private function truncate(string $value): string
    {
        return strlen($value) <= 28 ? $value : substr($value, 0, 27).'~';
    }

    private function reason(string $message): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return strlen($flat) <= 120 ? $flat : substr($flat, 0, 119).'~';
    }
}
