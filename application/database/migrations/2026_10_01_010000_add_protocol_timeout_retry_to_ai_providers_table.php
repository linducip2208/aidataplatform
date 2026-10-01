<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-provider protocol/timeout/retry knobs. All nullable: null means
     * "derive from provider_type + config/ai_providers.php defaults", so
     * existing rows keep working untouched.
     */
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->string('protocol', 32)->nullable()->after('provider_type');
            $table->unsignedInteger('timeout_seconds')->nullable()->after('priority');
            $table->unsignedTinyInteger('max_retries')->nullable()->after('timeout_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropColumn(['protocol', 'timeout_seconds', 'max_retries']);
        });
    }
};
