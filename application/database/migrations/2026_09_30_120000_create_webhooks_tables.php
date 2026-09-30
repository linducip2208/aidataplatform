<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbound webhook subscriptions + delivery log. Secrets use the
     * `encrypted` cast (APP_KEY at rest), are shown once at creation, and
     * never appear in logs or audit detail.
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->string('url', 1024);
            $table->text('secret');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('event', 128);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error', 512)->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
