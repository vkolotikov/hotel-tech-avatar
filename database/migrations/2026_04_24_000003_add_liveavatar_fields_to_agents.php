<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent LiveAvatar references.
 *
 * HeyGen's Streaming Avatar v1/v2 was retired in April 2026 and the
 * live talking-head product moved to a separate platform ("LiveAvatar"
 * at app.liveavatar.com, same vendor). Existing HeyGen avatars auto-
 * migrate to a LiveAvatar account on first sign-in; the avatar itself
 * is the same but the ID space is different.
 *
 * Columns:
 *   - liveavatar_avatar_id  — the platform's identifier for the
 *                             talking-head avatar assigned to this agent.
 *                             Null until ops wires it up.
 *   - liveavatar_voice_id   — optional override of the default voice for
 *                             this avatar. Null means "use the avatar's
 *                             stock voice".
 *
 * Both nullable so the feature is off-by-default per agent and only
 * lights up when an operator fills them in. The LiveAvatarController
 * returns a 422 when asked for a session on an agent without
 * liveavatar_avatar_id set, rather than falling back to a stock avatar —
 * better to be explicit about "this agent isn't voice-mode-ready yet".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('liveavatar_avatar_id', 128)->nullable()->after('openai_voice');
            $table->string('liveavatar_voice_id',  128)->nullable()->after('liveavatar_avatar_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['liveavatar_avatar_id', 'liveavatar_voice_id']);
        });
    }
};
