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

    public function test_eval_runs_and_results_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('eval_runs'));
        $this->assertTrue(Schema::hasTable('eval_results'));

        foreach (['dataset_id', 'started_at', 'finished_at', 'cases_total', 'cases_passed', 'cases_failed', 'score_pct', 'trigger', 'trace_id', 'metadata_json'] as $col) {
            $this->assertTrue(Schema::hasColumn('eval_runs', $col), "eval_runs missing {$col}");
        }
        foreach (['run_id', 'case_id', 'assertion_index', 'assertion_type', 'passed', 'actual_response', 'reason'] as $col) {
            $this->assertTrue(Schema::hasColumn('eval_results', $col), "eval_results missing {$col}");
        }
    }

    public function test_eval_results_cascades_when_run_deleted(): void
    {
        $datasetId = DB::table('eval_datasets')->insertGetId([
            'slug' => 'cascade-demo',
            'name' => 'Cascade demo',
            'vertical_slug' => 'hotel',
            'source_path' => 'x.yaml',
            'source_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $caseId = DB::table('eval_cases')->insertGetId([
            'dataset_id' => $datasetId,
            'slug' => 'c1',
            'prompt' => 'p',
            'assertions_json' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('eval_runs')->insertGetId([
            'dataset_id' => $datasetId,
            'started_at' => now(),
            'trigger' => 'manual',
        ]);
        DB::table('eval_results')->insert([
            'run_id' => $runId,
            'case_id' => $caseId,
            'assertion_index' => 0,
            'assertion_type' => 'contains_text',
            'passed' => true,
        ]);

        DB::table('eval_runs')->where('id', $runId)->delete();

        $this->assertSame(0, DB::table('eval_results')->where('run_id', $runId)->count());
    }
}
