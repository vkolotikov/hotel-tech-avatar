<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('source_url', 1000)->nullable();
            $table->string('evidence_grade', 32)->nullable();
            $table->string('licence', 64)->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('checksum', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index('evidence_grade');
            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
