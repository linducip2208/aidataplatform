<?php

namespace Tests\Fixtures;

/**
 * Mutable per-test knobs for the shared engine fake.
 *
 * Read at request time by EngineFakes::respond(), so a test mutates these
 * AFTER EngineFakes::install() instead of registering a second Http::fake()
 * (which could never win: stub callbacks resolve first-registered-wins).
 *
 * The failingJobs / qualityScores maps are keyed by import job id, the same
 * pattern OpsCommandOutputTest uses: most rows healthy, one row broken.
 */
class EngineFakeState
{
    /** Score reported for /imports/quality/{id} unless qualityScores[id] overrides it. */
    public float $qualityScore = 0.91;

    /**
     * The engine's own `passed` flag. Null lets the fake follow the Laravel
     * threshold (the fallback runQuality() applies when the key is absent);
     * an explicit bool pins the engine's verdict independently of the score.
     */
    public ?bool $enginePassed = null;

    /** Status reported for /imports/jobs/{id}. */
    public string $jobStatus = 'succeeded';

    /** Status reported for /imports/commit. */
    public string $commitStatus = 'queued';

    /** Whether the upload's `validation.ok` comes back true. */
    public bool $uploadValid = true;

    /** When true /health answers 503, i.e. the engine is unreachable. */
    public bool $healthDown = false;

    /** Overrides the /readiness body so a failing dependency can be described. */
    public ?array $readiness = null;

    /** When set, /models answers with this HTTP status (a key rejection). */
    public ?int $modelsStatus = null;

    /** The error body /models returns alongside a rejection. */
    public string $modelsDetail = 'service key rejected';

    /** @var array<int, int> import job id => HTTP status the engine fails with */
    public array $failingJobs = [];

    /** @var array<int, float> import job id => quality score the engine reports */
    public array $qualityScores = [];

    /**
     * When true the `passed` key is omitted from the quality report, so the
     * test exercises the runQuality() fallback onto
     * config('ai_engine.quality_threshold') instead of the engine's verdict.
     */
    public bool $omitPassedFlag = false;
}
