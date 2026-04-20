<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->foreign('external_source_id')
                ->references('id')->on('external_source_cache')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->dropForeign(['external_source_id']);
        });
    }
};
