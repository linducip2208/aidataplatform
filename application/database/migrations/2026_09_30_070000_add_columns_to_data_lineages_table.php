<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Column-level lineage: which source column became which canonical
     * column. Nullable so every node-level edge written before this
     * migration keeps working untouched; plain strings on purpose, matching
     * the untyped (type, id) node addressing of the base table.
     */
    public function up(): void
    {
        Schema::table('data_lineages', function (Blueprint $table): void {
            $table->string('source_column', 191)->nullable()->after('source_id');
            $table->string('target_column', 191)->nullable()->after('target_id');
            $table->index(['source_type', 'source_id', 'source_column'], 'data_lineages_source_col_index');
        });
    }

    public function down(): void
    {
        Schema::table('data_lineages', function (Blueprint $table): void {
            $table->dropIndex('data_lineages_source_col_index');
            $table->dropColumn(['source_column', 'target_column']);
        });
    }
};
