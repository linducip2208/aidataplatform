<?php

namespace App\Console\Commands;

use App\Enums\DatasetStatus;
use App\Exceptions\AiEngineException;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\PlatformHealth;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SyncImportStatusCommand extends Command
{
    protected $signature = 'sync:import-status
                            {--dataset= : Reconcile only this dataset UUID}
                            {--limit=50 : Maximum datasets to reconcile in one run}
                            {--dry-run : List what would be reconciled without calling the engine}';

    protected $description = 'Reconcile the local dataset status mirror with the AI engine import jobs';

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

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $target = $this->resolveTarget();
            $datasets = $target instanceof Dataset ? collect([$target]) : $this->pending($limit);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('cannot read the datasets table: '.$this->reason($exception->getMessage()));
            $this->line('  remedy: run `php artisan migrate --force`');

            return self::FAILURE;
        }

        if ($datasets->isEmpty()) {
            $this->info('No dataset is waiting on the engine. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $changed = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($datasets as $dataset) {
            $before = $dataset->status();

            if ($dryRun) {
                $unchanged++;

                $this->line(sprintf(
                    '  <comment>dry-run</comment>  %s  %s  %s -> ?',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $before->value,
                ));

                continue;
            }

            try {
                $job = $this->ingestion->syncStatus($dataset);
            } catch (AiEngineException $exception) {
                $failed++;

                Log::warning('sync.import_status_failed', [
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

                Log::error('sync.import_status_failed', [
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

            $after = $dataset->status();

            if ($before->value === $after->value) {
                $unchanged++;

                $this->line(sprintf(
                    '  <fg=gray>HELD  </>  %s  %s  %s  %s',
                    $dataset->uuid,
                    $this->truncate((string) $dataset->name),
                    $before->value,
                    $this->engineSuffix($job),
                ));

                continue;
            }

            $changed++;

            $this->line(sprintf(
                '  <info>MOVED </>  %s  %s  %s -> %s%s',
                $dataset->uuid,
                $this->truncate((string) $dataset->name),
                $before->value,
                $after->value,
                $this->engineSuffix($job),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'Reconciled %d dataset(s): %d moved, %d unchanged, %d failed.%s',
            $datasets->count(),
            $changed,
            $unchanged,
            $failed,
            $this->backlogNote($limit),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The engine round trip is the whole point of this command, so a broken
     * configuration is reported once with the remedy `platform:doctor` gives
     * instead of being repeated for every dataset.
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

        if (! $dataset->import_job_id) {
            throw new InvalidArgumentException('Dataset "'.$uuid.'" has no import job to reconcile.');
        }

        return $dataset;
    }

    /**
     * Oldest first, so a truncated run always clears the oldest stuck rows and
     * repeated runs converge instead of revisiting the same head every night.
     *
     * @return Collection<int, Dataset>
     */
    private function pending(int $limit): Collection
    {
        return $this->staleQuery()->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
    }

    /** @return Builder<Dataset> */
    private function staleQuery(): Builder
    {
        return Dataset::query()
            ->whereNotNull('import_job_id')
            ->whereIn('status', $this->openStatuses());
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return array_values(array_map(
            static fn (DatasetStatus $status): string => $status->value,
            array_filter(DatasetStatus::cases(), static fn (DatasetStatus $status): bool => ! $status->isTerminal()),
        ));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function engineSuffix(array $job): string
    {
        $status = (string) ($job['status'] ?? '');

        if ($status === '') {
            return '  (engine reported no status)';
        }

        $rows = $job['total_rows'] ?? null;

        return '  (engine: '.$status.($rows !== null ? ', '.$rows.' rows' : '').')';
    }

    private function backlogNote(int $limit): string
    {
        if ($this->option('dataset') !== null) {
            return '';
        }

        $pending = $this->staleQuery()->count();

        return $pending > $limit ? ' '.$pending.' still pending; raise --limit or run again.' : '';
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
