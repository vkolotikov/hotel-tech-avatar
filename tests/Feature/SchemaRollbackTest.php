<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaRollbackTest extends TestCase
{
    public function test_can_rollback_phase_0_migrations_and_reapply(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true]);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('verticals'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('knowledge_chunks'));

        // Rollback all Phase 0 migrations (27 under 2026_04_19_* + 4 eval under 2026_04_20_*).
        Artisan::call('migrate:rollback', ['--step' => 31]);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('subscription_entitlements'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('verticals'));

        // Original hotel columns still intact
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('agents'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('agents', 'system_instructions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('agents', 'vertical_id'));

        // Re-apply
        Artisan::call('migrate', ['--seed' => true]);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('verticals'));
    }
}
