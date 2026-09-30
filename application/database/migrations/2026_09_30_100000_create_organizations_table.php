<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-company profile for white-labeling (name, tagline, logo).
     * This is NOT multi-tenancy: the platform serves one organization per
     * deployment and all data stays global. The table holds one row in
     * practice; `Organization::current()` reads the earliest.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('tagline', 255)->nullable();
            $table->string('logo_path', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
