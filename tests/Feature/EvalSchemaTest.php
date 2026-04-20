<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EvalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_eval_datasets_and_cases_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('eval_datasets'));
        $this->assertTrue(Schema::hasTable('eval_cases'));

        foreach (['slug', 'name', 'vertical_slug', 'avatar_slug', 'description', 'source_path', 'source_hash'] as $col) {
            $this->assertTrue(Schema::hasColumn('eval_datasets', $col), "eval_datasets missing {$col}");
        }
        foreach (['dataset_id', 'slug', 'prompt', 'context_json', 'stub_response', 'assertions_json'] as $col) {
            $this->assertTrue(Schema::hasColumn('eval_cases', $col), "eval_cases missing {$col}");
        }
    }

    public function test_eval_case_slug_is_unique_within_dataset(): void
    {
        $datasetId = DB::table('eval_datasets')->insertGetId([
            'slug' => 'demo',
            'name' => 'Demo',
            'vertical_slug' => 'hotel',
            'avatar_slug' => null,
            'description' => null,
            'source_path' => 'docs/eval/datasets/hotel/demo/demo.yaml',
            'source_hash' => str_repeat('0', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('eval_cases')->insert([
            'dataset_id' => $datasetId,
            'slug' => 'case-1',
            'prompt' => 'Hi',
            'context_json' => json_encode([]),
            'stub_response' => 'Hello',
            'assertions_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('eval_cases')->insert([
            'dataset_id' => $datasetId,
            'slug' => 'case-1',
            'prompt' => 'Hi again',
            'context_json' => json_encode([]),
            'stub_response' => 'Hello',
            'assertions_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
