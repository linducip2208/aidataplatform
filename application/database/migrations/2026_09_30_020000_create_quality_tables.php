<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('dataset_type', 64)->default('sales')->index();
            $table->string('column', 256)->nullable();
            $table->string('rule_type', 32)->index();
            $table->json('params')->nullable();
            $table->string('severity', 16)->default('error');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['dataset_type', 'active']);
        });

        Schema::create('quality_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('dataset_type', 64)->default('sales')->index();
            $table->json('rules')->nullable();
            $table->string('severity', 16)->default('error');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_profiles');
        Schema::dropIfExists('quality_rules');
    }
};
