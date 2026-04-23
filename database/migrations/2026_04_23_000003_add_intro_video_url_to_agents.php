<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds intro_video_url for the per-avatar introduction video shown on
 * the mobile avatar selection screen. Path is relative to the API base
 * (same convention as avatar_image_url / chat_background_url), e.g.
 * "/assets/avatars/videos/Nora.mp4".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('intro_video_url', 255)->nullable()->after('chat_background_url');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('intro_video_url');
        });
    }
};
