<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments were previously conversation-scoped only. Link each
 * attachment to the specific message it was sent with, so the chat
 * UI can render inline and message-level retrieval can include them.
 *
 * Nullable because existing rows pre-date the link, and admin uploads
 * (knowledge files etc.) don't belong to any user message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_attachments', function (Blueprint $table) {
            $table->foreignId('message_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained('messages')
                ->nullOnDelete();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_attachments', function (Blueprint $table) {
            $table->dropForeign(['message_id']);
            $table->dropIndex(['message_id']);
            $table->dropColumn('message_id');
        });
    }
};
