<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('agent_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(3072)');
            // pgvector HNSW caps at 2000 dims for the default `vector` opclass.
            // ANN index deferred to Phase 1 (halfvec HNSW / ivfflat / dim reduction)
            // — picked against eval harness results. Sequential scans work for now.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
