<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->boolean('passed');
            $table->jsonb('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_events');
    }
};
