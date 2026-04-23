<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill intro_video_url on existing wellness avatars so they pick up
 * the uploaded intro videos without needing the seeder to re-run.
 *
 * Only touches rows where intro_video_url is NULL, so super-admin
 * edits are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $wellness = DB::table('verticals')->where('slug', 'wellness')->value('id');
        if (!$wellness) {
            return;
        }

        $map = [
            'integra' => '/assets/avatars/videos/Integra.mp4',
            'nora'    => '/assets/avatars/videos/Nora.mp4',
            'luna'    => '/assets/avatars/videos/Luna.mp4',
            'zen'     => '/assets/avatars/videos/Zen.mp4',
            'axel'    => '/assets/avatars/videos/Axel.mp4',
            'aura'    => '/assets/avatars/videos/Aura.mp4',
        ];

        foreach ($map as $slug => $path) {
            DB::table('agents')
                ->where('vertical_id', $wellness)
                ->where('slug', $slug)
                ->whereNull('intro_video_url')
                ->update([
                    'intro_video_url' => $path,
                    'updated_at'      => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive; no-op.
    }
};
