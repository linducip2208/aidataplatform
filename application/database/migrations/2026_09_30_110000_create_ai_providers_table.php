<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BYOK provider registry (metadata only — never API keys).
     *
     * Secrets are written straight to the engine env block on save and exist
     * nowhere else: not in this table, not in logs, not in audit detail.
     * The active provider is the enabled row with the lowest priority number;
     * the engine itself stays env-driven, so this table can never strand a
     * request when the database is unreachable.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('provider_type', 32);
            $table->string('base_url', 512);
            $table->string('model', 256);
            $table->string('embedding_model', 256)->nullable();
            $table->json('capabilities')->nullable();
            $table->decimal('input_price_per_million', 10, 6)->nullable();
            $table->decimal('output_price_per_million', 10, 6)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 32)->nullable();
            $table->string('last_test_note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
