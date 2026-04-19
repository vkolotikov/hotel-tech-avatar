<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('domain', 64)->nullable()->after('role');
            $table->jsonb('persona_json')->nullable();
            $table->jsonb('scope_json')->nullable();
            $table->jsonb('red_flag_rules_json')->nullable();
            $table->jsonb('handoff_rules_json')->nullable();
            $table->unsignedBigInteger('active_prompt_version_id')->nullable();
            // FK added in Task B.6 once agent_prompt_versions exists.
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn([
                'domain', 'persona_json', 'scope_json',
                'red_flag_rules_json', 'handoff_rules_json',
                'active_prompt_version_id',
            ]);
        });
    }
};
