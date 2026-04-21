<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_verified')->nullable()->after('verification_status');
            $table->json('verification_failures_json')->nullable()->after('is_verified');
            $table->unsignedInteger('verification_latency_ms')->nullable()->after('verification_failures_json');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'verification_failures_json', 'verification_latency_ms']);
        });
    }
};
