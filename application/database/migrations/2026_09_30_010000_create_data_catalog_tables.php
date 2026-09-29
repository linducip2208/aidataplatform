<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema_snapshot')->nullable();
            $table->string('schema_hash', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'version']);
            $table->index('dataset_id');
        });

        Schema::create('column_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('dtype', 64)->default('string');
            $table->boolean('nullable')->default(true);
            $table->boolean('is_pii')->default(false);
            $table->string('sensitivity', 32)->default('internal');
            $table->text('business_description')->nullable();
            $table->unsignedInteger('distinct_count')->nullable();
            $table->float('null_pct')->nullable();
            $table->string('min_value', 191)->nullable();
            $table->string('max_value', 191)->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'name']);
            $table->index('dataset_id');
        });

        Schema::create('data_lineages', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 64);
            $table->string('source_id', 191);
            $table->string('target_type', 64);
            $table->string('target_id', 191);
            $table->text('transform')->nullable();
            $table->string('run_reference', 191)->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['target_type', 'target_id']);
            $table->index('run_reference');
        });

        Schema::create('data_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('datasets')->cascadeOnDelete()->unique();
            $table->string('owner', 191);
            $table->string('schema_hash', 64)->nullable();
            $table->unsignedInteger('freshness_sla_hours')->default(72);
            $table->float('quality_threshold')->default(0.75);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_contracts');
        Schema::dropIfExists('data_lineages');
        Schema::dropIfExists('column_metadata');
        Schema::dropIfExists('dataset_versions');
    }
};
