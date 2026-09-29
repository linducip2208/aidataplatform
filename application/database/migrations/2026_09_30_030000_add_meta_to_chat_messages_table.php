<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token/cost ledger summary per assistant turn.
     *
     * The engine owns the full ledger (`ai_usage` on the FastAPI side,
     * served at `GET /api/v1/ai/usage` and proxied as `GET /api/ai/usage`);
     * Laravel keeps no mirror table by design. This column stores only the
     * single-turn `usage` summary the engine returned with the answer, next
     * to the message it prices. Nullable so every existing row stays valid,
     * and never merged into `evidence`, which keeps the exact engine shape.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
