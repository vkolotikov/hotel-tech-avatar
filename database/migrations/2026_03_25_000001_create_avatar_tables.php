<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 100);
            $table->string('role', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('avatar_image_url', 255)->nullable();
            $table->string('chat_background_url', 255)->nullable();
            $table->text('system_instructions')->nullable();
            $table->text('knowledge_text')->nullable();
            $table->jsonb('knowledge_files_json')->nullable();
            $table->string('openai_model', 120)->default('gpt-4o');
            $table->string('openai_voice', 64)->nullable();
            $table->boolean('use_advanced_ai')->default(false);
            $table->string('openai_vector_store_id', 128)->nullable();
            $table->string('knowledge_sync_status', 24)->default('idle');
            $table->timestamp('knowledge_synced_at')->nullable();
            $table->text('knowledge_last_error')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index('role');
            $table->index('is_published');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->timestamps();

            $table->index('agent_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('role', 20);  // 'user' or 'agent'
            $table->text('content');
            $table->string('ai_provider', 32)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('ai_latency_ms')->nullable();
            $table->jsonb('ui_json')->nullable();
            $table->boolean('retrieval_used')->default(false);
            $table->unsignedInteger('retrieval_source_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['role', 'created_at']);
        });

        Schema::create('agent_knowledge_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('local_path', 512);
            $table->char('file_hash', 64)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('openai_file_id', 128)->nullable();
            $table->string('vector_store_id', 128)->nullable();
            $table->string('sync_status', 24)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'local_path']);
            $table->index('sync_status');
            $table->index('openai_file_id');
        });

        Schema::create('conversation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('file_name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_attachments');
        Schema::dropIfExists('agent_knowledge_files');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('agents');
    }
};
