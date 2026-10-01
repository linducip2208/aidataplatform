<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Models discovered from a provider's models endpoint (e.g. OpenCode Go
     * GET /models). Metadata only: capabilities detected from the API
     * response, never invented. The provider's default `model` column stays
     * the single pinned default; this table is the discoverable catalog.
     */
    public function up(): void
    {
        Schema::create('ai_provider_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('external_id', 256);
            $table->string('name', 256)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'external_id']);
            $table->index(['ai_provider_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_models');
    }
};
