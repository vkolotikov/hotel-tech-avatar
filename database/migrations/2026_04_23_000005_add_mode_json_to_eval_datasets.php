<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist per-dataset runtime mode so the harness can choose between
 * stubbed (use stub_response from each case) and live (call the model
 * via LiveResolver). Without this column, the Runner's lookup of
 * $dataset->mode_json?->mode always fell through to 'stubbed'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eval_datasets', function (Blueprint $table) {
            $table->jsonb('mode_json')->nullable()->after('source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('eval_datasets', function (Blueprint $table) {
            $table->dropColumn('mode_json');
        });
    }
};
