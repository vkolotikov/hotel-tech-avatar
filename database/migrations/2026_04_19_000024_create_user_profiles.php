<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->jsonb('goals')->nullable();
            $table->jsonb('conditions')->nullable();
            $table->jsonb('medications')->nullable();
            $table->jsonb('dietary_flags')->nullable();
            $table->jsonb('wearables_connected')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->char('sex_at_birth', 1)->nullable();
            $table->string('activity_level', 16)->nullable();
            $table->jsonb('profile_metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
