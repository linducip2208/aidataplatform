<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Directed lineage edges between platform nodes.
 *
 * Nodes are addressed generically as (type, id) pairs so one table covers
 * every hop: `import_job:42 -> dataset:<uuid> -> table:fact_sales ->
 * model:churn:v3`. `source_id`/`target_id` are strings on purpose: dataset
 * uuids and engine integer ids share the same edge table.
 *
 * Column granularity rides on the same rows: a mapping-stage edge carries
 * `source_column` (the uploaded header) and `target_column` (the canonical
 * field it was mapped to). Node-level edges leave both null.
 */
class DataLineage extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'source_id',
        'source_column',
        'target_type',
        'target_id',
        'target_column',
        'transform',
        'run_reference',
    ];

    /**
     * Record the upload/commit edge `import_job:<jobId> -> dataset:<uuid>`.
     *
     * Integration hook (called by master, one line, inside
     * `DatasetIngestionService::commit()` after the engine confirms the load):
     *
     *     \App\Models\DataLineage::recordImportLineage($dataset, (int) $dataset->import_job_id);
     */
    public static function recordImportLineage(Dataset $dataset, int|string $importJobId, ?string $transform = null, ?string $runReference = null): self
    {
        return static::recordTransformation(
            'import_job',
            (string) $importJobId,
            'dataset',
            $dataset->uuid,
            $transform ?? 'etl_load:'.$dataset->dataset_type,
            $runReference ?? (string) $importJobId,
        );
    }

    public static function recordTransformation(
        string $sourceType,
        int|string $sourceId,
        string $targetType,
        int|string $targetId,
        ?string $transform = null,
        ?string $runReference = null,
    ): self {
        return static::query()->firstOrCreate(
            [
                'source_type' => $sourceType,
                'source_id' => (string) $sourceId,
                'target_type' => $targetType,
                'target_id' => (string) $targetId,
                'transform' => $transform,
                'run_reference' => $runReference,
            ],
            [],
        );
    }

    /**
     * Record the column mapping of a dataset as lineage edges.
     *
     * One edge per mapped header: `dataset:<uuid>.<source> ->
     * dataset:<uuid>.<canonical>` with `transform = column_mapping`. The
     * mapping form is the current state, so edges from a previous save that
     * are no longer mapped are removed — otherwise impact analysis would
     * blame columns the pipeline no longer reads. Returns the edge count.
     */
    public static function recordColumnMapping(Dataset $dataset, array $mappings, ?string $runReference = null): int
    {
        $query = static::query()
            ->where('source_type', 'dataset')
            ->where('source_id', $dataset->uuid)
            ->where('target_type', 'dataset')
            ->where('target_id', $dataset->uuid)
            ->where('transform', 'column_mapping');

        $query->delete();

        $count = 0;

        foreach ($mappings as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);

            if ($source === '' || $target === '') {
                continue;
            }

            static::query()->create([
                'source_type' => 'dataset',
                'source_id' => $dataset->uuid,
                'source_column' => $source,
                'target_type' => 'dataset',
                'target_id' => $dataset->uuid,
                'target_column' => $target,
                'transform' => 'column_mapping',
                'run_reference' => $runReference,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Column edges touching one dataset, for the columns endpoint and for
     * "what breaks if this header changes" impact reads.
     *
     * @return list<array{source_column: string, target_column: string, run_reference: ?string}>
     */
    public static function columnEdgesFor(Dataset $dataset): array
    {
        return static::query()
            ->where('source_type', 'dataset')
            ->where('source_id', $dataset->uuid)
            ->where('target_type', 'dataset')
            ->where('target_id', $dataset->uuid)
            ->where('transform', 'column_mapping')
            ->orderBy('source_column')
            ->get()
            ->map(static fn (self $edge): array => [
                'source_column' => (string) $edge->source_column,
                'target_column' => (string) $edge->target_column,
                'run_reference' => $edge->run_reference,
            ])
            ->all();
    }

    /**
     * Walk backwards from a node: every edge (and node) the data flowed
     * through to reach it, breadth-first up to $depth hops.
     *
     * @return array{nodes: list<array{type: string, id: string}>, edges: list<array<string, mixed>>}
     */
    public static function upstream(string $type, int|string $id, int $depth = 5): array
    {
        return static::traverse((string) $id, $type, $depth, 'up');
    }

    /**
     * Walk forwards from a node: everything derived from it, breadth-first
     * up to $depth hops.
     *
     * @return array{nodes: list<array{type: string, id: string}>, edges: list<array<string, mixed>>}
     */
    public static function downstream(string $type, int|string $id, int $depth = 5): array
    {
        return static::traverse((string) $id, $type, $depth, 'down');
    }

    /**
     * Both directions at once, for the graph view.
     *
     * @return array{node: array{type: string, id: string}, upstream: mixed, downstream: mixed}
     */
    public static function graphFor(string $type, int|string $id, int $depth = 3): array
    {
        return [
            'node' => ['type' => $type, 'id' => (string) $id],
            'upstream' => static::upstream($type, $id, $depth),
            'downstream' => static::downstream($type, $id, $depth),
        ];
    }

    /**
     * @return array{nodes: list<array{type: string, id: string}>, edges: list<array<string, mixed>>}
     */
    protected static function traverse(string $id, string $type, int $depth, string $direction): array
    {
        $depth = max(1, min($depth, 10));
        $nodes = [['type' => $type, 'id' => $id]];
        $seen = [$type."\0".$id];
        $edges = [];
        $seenEdges = [];
        $frontier = [['type' => $type, 'id' => $id]];

        for ($hop = 0; $hop < $depth && $frontier !== []; $hop++) {
            $next = [];

            foreach ($frontier as $node) {
                $rows = $direction === 'up'
                    ? static::query()->where('target_type', $node['type'])->where('target_id', $node['id'])->get()
                    : static::query()->where('source_type', $node['type'])->where('source_id', $node['id'])->get();

                foreach ($rows as $row) {
                    $key = $row->getKey();

                    if (! isset($seenEdges[$key])) {
                        $seenEdges[$key] = true;
                        $edges[] = [
                            'id' => $key,
                            'source_type' => $row->source_type,
                            'source_id' => $row->source_id,
                            'source_column' => $row->source_column,
                            'target_type' => $row->target_type,
                            'target_id' => $row->target_id,
                            'target_column' => $row->target_column,
                            'transform' => $row->transform,
                            'run_reference' => $row->run_reference,
                        ];
                    }

                    $neighbour = $direction === 'up'
                        ? ['type' => $row->source_type, 'id' => $row->source_id]
                        : ['type' => $row->target_type, 'id' => $row->target_id];

                    $nodeKey = $neighbour['type']."\0".$neighbour['id'];

                    if (! isset($seen[$nodeKey])) {
                        $seen[$nodeKey] = true;
                        $nodes[] = $neighbour;
                        $next[] = $neighbour;
                    }
                }
            }

            $frontier = $next;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
