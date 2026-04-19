<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE conversations
               SET vertical_id = agents.vertical_id
              FROM agents
             WHERE agents.id = conversations.agent_id
               AND conversations.vertical_id IS NULL
        ');
    }

    public function down(): void
    {
        DB::table('conversations')->update(['vertical_id' => null]);
    }
};
