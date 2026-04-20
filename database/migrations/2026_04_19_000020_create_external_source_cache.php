<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_source_cache', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_id', 128);
            $table->string('title', 500);
            $table->string('url', 1000)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_source_cache');
    }
};
