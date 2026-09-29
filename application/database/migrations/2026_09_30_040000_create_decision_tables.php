<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_cases', function (Blueprint $table) {
            $table->id();
            $table->json('subject')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('decision_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('decision_cases')->cascadeOnDelete();
            $table->string('action', 512)->default('');
            $table->json('impact')->nullable();
            $table->float('confidence')->default(0);
            $table->json('evidence')->nullable();
            $table->json('explanation')->nullable();
            $table->float('score')->default(0);
            $table->string('rule', 128)->default('');
            $table->timestamps();
        });

        Schema::create('decision_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('decision_cases')->cascadeOnDelete();
            $table->string('actor', 128)->default('');
            $table->string('decision', 64)->default('');
            $table->text('rationale')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_audits');
        Schema::dropIfExists('decision_recommendations');
        Schema::dropIfExists('decision_cases');
    }
};
