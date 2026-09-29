<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TmpProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe(): void
    {
        $seen = [];

        \App\Models\Dataset::saving(function (\Illuminate\Database\Eloquent\Model $m) use (&$seen): void {
            $seen = array_merge($seen, array_keys($m->getAttributes()));
        });

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        fwrite(STDERR, 'CAPTURED: '.implode(',', array_unique($seen)).PHP_EOL);
        fwrite(STDERR, 'FILLABLE:  '.implode(',', (new \App\Models\Dataset)->getFillable()).PHP_EOL);

        $this->assertTrue(true);
    }
}
