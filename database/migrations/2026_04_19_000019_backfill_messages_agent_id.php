<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE messages
               SET agent_id = conversations.agent_id
              FROM conversations
             WHERE conversations.id = messages.conversation_id
               AND messages.role = 'agent'
               AND messages.agent_id IS NULL
        ");
    }

    public function down(): void
    {
        // no-op
    }
};
