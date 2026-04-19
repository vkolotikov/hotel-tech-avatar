<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgvector_extension_is_installed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        $row = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'vector'");
        $this->assertNotNull($row, 'pgvector extension not installed');
    }

    public function test_vector_type_roundtrip(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        DB::statement('CREATE TEMP TABLE _vtest (id serial primary key, v vector(3))');
        DB::statement("INSERT INTO _vtest (v) VALUES ('[1,2,3]')");
        $row = DB::selectOne('SELECT v::text AS v FROM _vtest LIMIT 1');
        $this->assertSame('[1,2,3]', $row->v);
    }
}
