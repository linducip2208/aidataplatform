<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\User;

/**
 * Internal data tools the assistant may call through a Responses-capable
 * provider. Hard rules, enforced here rather than trusted to the model:
 *
 * - Only the allowlisted names below are accepted; anything else (shell,
 *   filesystem, network, eval) is refused with a structured error.
 * - Only tools with a real local backing are advertised to the model.
 *   Engine-owned tools (run_sql, run_python, ...) are reserved names that
 *   resolve to an explicit `unsupported` result — never fake data.
 * - Reads mirror the visibility of the datasets index (global catalog).
 */
class LlmToolGateway
{
    /**
     * Tools with a real local executor. Everything else in RESERVED_TOOLS
     * is a known name without a local backing.
     */
    public const EXECUTABLE_TOOLS = [
        'list_datasets',
        'get_dataset_schema',
        'describe_columns',
        'preview_dataset',
    ];

    public const RESERVED_TOOLS = [
        'run_sql',
        'run_python',
        'get_statistics',
        'create_chart',
        'save_analysis',
    ];

    /** @return list<string> */
    public static function allowedTools(): array
    {
        return [...self::EXECUTABLE_TOOLS, ...self::RESERVED_TOOLS];
    }

    public static function supports(string $tool): bool
    {
        return in_array($tool, self::allowedTools(), true);
    }

    public static function isExecutable(string $tool): bool
    {
        return in_array($tool, self::EXECUTABLE_TOOLS, true);
    }

    /**
     * Tool definitions in OpenAI function shape, executable tools only, for
     * the Responses API `tools` parameter.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_datasets',
                    'description' => 'List datasets in the catalog, newest first.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_dataset_schema',
                    'description' => 'Return the stored column schema of one dataset by id or uuid.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dataset' => ['type' => ['integer', 'string'], 'description' => 'Dataset id or uuid.'],
                        ],
                        'required' => ['dataset'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'describe_columns',
                    'description' => 'Describe one dataset columns with dtypes and a dtype summary.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dataset' => ['type' => ['integer', 'string'], 'description' => 'Dataset id or uuid.'],
                        ],
                        'required' => ['dataset'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'preview_dataset',
                    'description' => 'Return dataset metadata preview (columns, row count, quality). Row content lives in the source file and the AI engine, so no rows are returned here.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dataset' => ['type' => ['integer', 'string'], 'description' => 'Dataset id or uuid.'],
                        ],
                        'required' => ['dataset'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, result?: mixed, error?: string}
     */
    public function execute(string $tool, array $args = [], ?User $user = null): array
    {
        if (! self::supports($tool)) {
            return ['ok' => false, 'error' => "Unsupported tool [{$tool}]. Shell, filesystem, network and eval tools are never available."];
        }

        if (! self::isExecutable($tool)) {
            return ['ok' => false, 'error' => "Tool [{$tool}] is unsupported here: engine-owned with no local executor in this deployment."];
        }

        return match ($tool) {
            'list_datasets' => $this->listDatasets($args),
            'get_dataset_schema' => $this->datasetSchema($args),
            'describe_columns' => $this->describeColumns($args),
            'preview_dataset' => $this->previewDataset($args),
            default => ['ok' => false, 'error' => "Unsupported tool [{$tool}]."],
        };
    }

    /** @param  array<string, mixed>  $args */
    private function listDatasets(array $args): array
    {
        $limit = (int) ($args['limit'] ?? 10);
        $limit = max(1, min($limit, 50));

        $rows = Dataset::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'uuid', 'name', 'dataset_type', 'source_filename', 'row_count'])
            ->map(static fn (Dataset $dataset): array => [
                'id' => $dataset->getKey(),
                'uuid' => $dataset->uuid,
                'name' => $dataset->name,
                'dataset_type' => $dataset->dataset_type,
                'source_filename' => $dataset->source_filename,
                'row_count' => $dataset->row_count !== null ? (int) $dataset->row_count : null,
            ])
            ->all();

        return ['ok' => true, 'result' => ['datasets' => $rows, 'count' => count($rows)]];
    }

    /** @param  array<string, mixed>  $args */
    private function datasetSchema(array $args): array
    {
        $dataset = $this->findDataset($args);

        if (! $dataset instanceof Dataset) {
            return ['ok' => false, 'error' => 'Dataset not found. Pass a valid dataset id or uuid.'];
        }

        return ['ok' => true, 'result' => [
            'id' => $dataset->getKey(),
            'uuid' => $dataset->uuid,
            'name' => $dataset->name,
            'dataset_type' => $dataset->dataset_type,
            'status' => $dataset->status()->value,
            'columns' => $dataset->columns ?? [],
            'row_count' => $dataset->row_count !== null ? (int) $dataset->row_count : null,
        ]];
    }

    /** @param  array<string, mixed>  $args */
    private function describeColumns(array $args): array
    {
        $resolved = $this->datasetSchema($args);

        if (! $resolved['ok']) {
            return $resolved;
        }

        $columns = $resolved['result']['columns'] ?? [];
        $dtypes = [];

        foreach (is_array($columns) ? $columns : [] as $column) {
            $dtype = is_array($column) ? (string) ($column['dtype'] ?? $column['type'] ?? 'unknown') : 'unknown';
            $dtypes[$dtype] = ($dtypes[$dtype] ?? 0) + 1;
        }

        $resolved['result']['column_count'] = is_array($columns) ? count($columns) : 0;
        $resolved['result']['dtype_summary'] = $dtypes;

        return $resolved;
    }

    /** @param  array<string, mixed>  $args */
    private function previewDataset(array $args): array
    {
        $resolved = $this->datasetSchema($args);

        if (! $resolved['ok']) {
            return $resolved;
        }

        $resolved['result']['rows'] = [];
        $resolved['result']['rows_note'] = 'Row content is not stored in Laravel; read it from the source file or the AI engine preview.';

        return $resolved;
    }

    /** @param  array<string, mixed>  $args */
    private function findDataset(array $args): ?Dataset
    {
        $identifier = $args['dataset'] ?? null;

        if (is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) {
            return Dataset::query()->find((int) $identifier);
        }

        if (is_string($identifier) && trim($identifier) !== '') {
            return Dataset::query()->where('uuid', trim($identifier))->first();
        }

        return null;
    }
}
