<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor', 128)->default('system');
            $table->string('action', 128)->index();
            $table->string('resource', 191)->default('');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('ip', 64)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['resource', 'resource_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
