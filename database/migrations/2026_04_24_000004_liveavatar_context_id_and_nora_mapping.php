<?php

use App\Models\Agent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part A — add liveavatar_context_id to agents.
 *
 *   LiveAvatar's LITE-mode session creation (POST /v2/embeddings)
 *   requires BOTH an avatar_id (the visual) and a context_id (the
 *   "personality" resource, created via POST /v1/contexts).
 *   We cache the context_id per agent so we only create it once,
 *   not every time a user opens voice mode.
 *
 * Part B — initial Nora mapping.
 *
 *   The first LiveAvatar avatar ID provided by ops — Nora's face.
 *   Other five agents stay null until ops picks avatars for them.
 *   Idempotent: only writes when the column is empty, so it won't
 *   clobber a later super-admin override.
 */
return new class extends Migration
{
    private const NORA_LIVEAVATAR_ID = '26393b8e-e944-4367-98ef-e2bc75c4b792';

    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('liveavatar_context_id', 128)
                ->nullable()
                ->after('liveavatar_voice_id');
        });

        $verticalId = DB::table('verticals')->where('slug', 'wellness')->value('id');
        if (!$verticalId) {
            return;
        }

        Agent::query()
            ->whereRaw('LOWER(slug) = ?', ['nora'])
            ->where('vertical_id', $verticalId)
            ->whereNull('liveavatar_avatar_id')
            ->update(['liveavatar_avatar_id' => self::NORA_LIVEAVATAR_ID]);
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('liveavatar_context_id');
        });
    }
};
