<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('id')->constrained('verticals')->restrictOnDelete();
            $table->index('vertical_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['vertical_id']);
            $table->dropIndex(['vertical_id']);
            $table->dropColumn('vertical_id');
        });
    }
};
