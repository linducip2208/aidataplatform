<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled + on-demand report history. The payload is the engine's
     * `ai/report` answer verbatim (narrative, KPI, sections); rendering
     * stays in the Blade view so a template change never needs a backfill.
     */
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 32)->default('weekly');
            $table->string('status', 32)->default('generated');
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['period', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
