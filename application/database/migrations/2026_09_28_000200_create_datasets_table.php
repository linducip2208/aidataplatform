<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 191);
            $table->string('dataset_type', 64)->default('sales')->index();
            $table->string('source_filename', 255)->nullable();
            $table->string('disk', 64)->default('local');
            $table->string('path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime', 128)->nullable();
            $table->string('checksum_sha256', 64)->nullable()->index();
            $table->string('status', 32)->default('uploaded')->index();
            $table->unsignedBigInteger('import_job_id')->nullable()->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('column_count')->default(0);
            $table->json('columns')->nullable();
            $table->json('mappings')->nullable();
            $table->json('metadata')->nullable();
            $table->float('quality_score')->nullable();
            $table->string('quality_verdict', 32)->nullable();
            $table->timestamp('quality_checked_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
