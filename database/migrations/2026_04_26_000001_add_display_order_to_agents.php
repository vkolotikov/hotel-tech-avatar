<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Sort key for the lobby + mobile home grid + admin sidebar.
            // Nullable so existing rows keep working until backfilled below.
            $table->integer('display_order')->nullable()->after('is_published');
            $table->index(['display_order'], 'agents_display_order_idx');
        });

        // Backfill: number rows alphabetically per vertical so the initial
        // ordering matches what users see today (queries currently sort by
        // name). A 10-step gap between rows leaves room for drag-and-drop
        // inserts without renumbering every neighbour.
        $rows = DB::table('agents')
            ->orderBy('vertical_id')
            ->orderBy('name')
            ->get(['id', 'vertical_id']);

        $perVerticalCounter = [];
        foreach ($rows as $row) {
            $key = $row->vertical_id ?? 'null';
            $next = $perVerticalCounter[$key] ?? 0;
            DB::table('agents')->where('id', $row->id)->update(['display_order' => $next]);
            $perVerticalCounter[$key] = $next + 10;
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex('agents_display_order_idx');
            $table->dropColumn('display_order');
        });
    }
};
