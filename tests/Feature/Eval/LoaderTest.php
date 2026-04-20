<?php

namespace Tests\Feature\Eval;

use App\Eval\Loader;
use App\Models\EvalCase;
use App\Models\EvalDataset;
use App\Models\EvalResult;
use App\Models\EvalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoaderTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir() . '/eval_loader_' . uniqid() . '.yaml';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
        parent::tearDown();
    }

    private function writeYaml(string $body): void
    {
        file_put_contents($this->tmpFile, $body);
    }

    public function test_sync_creates_dataset_and_cases(): void
    {
        $this->writeYaml(<<<YAML
slug: loader-test
name: Loader test
vertical: hotel
avatar_slug: hotel-concierge
description: fixture
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions:
      - type: contains_text
        value: Hello
  - slug: c2
    prompt: Bye
    stub_response: Goodbye
    assertions: []
YAML);

        $id = (new Loader())->sync($this->tmpFile);

        $ds = EvalDataset::findOrFail($id);
        $this->assertSame('loader-test', $ds->slug);
        $this->assertSame('hotel', $ds->vertical_slug);
        $this->assertSame('hotel-concierge', $ds->avatar_slug);
        $this->assertCount(2, $ds->cases);
        $this->assertSame('c1', $ds->cases[0]->slug);
        $this->assertSame([['type' => 'contains_text', 'value' => 'Hello']], $ds->cases[0]->assertions_json);
    }

    public function test_sync_is_idempotent_when_file_unchanged(): void
    {
        $this->writeYaml(<<<YAML
slug: idem
name: Idem
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions: []
YAML);

        $loader = new Loader();
        $id1 = $loader->sync($this->tmpFile);
        $hash1 = EvalDataset::find($id1)->source_hash;
        $id2 = $loader->sync($this->tmpFile);
        $hash2 = EvalDataset::find($id2)->source_hash;

        $this->assertSame($id1, $id2);
        $this->assertSame($hash1, $hash2);
        $this->assertSame(1, EvalCase::where('dataset_id', $id1)->count());
    }

    public function test_sync_replaces_cases_when_file_changes(): void
    {
        $this->writeYaml(<<<YAML
slug: change
name: Change
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions: []
YAML);
        $loader = new Loader();
        $id = $loader->sync($this->tmpFile);
        $this->assertSame(1, EvalCase::where('dataset_id', $id)->count());

        $this->writeYaml(<<<YAML
slug: change
name: Change
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hi there
    assertions: []
  - slug: c2
    prompt: Bye
    stub_response: Goodbye
    assertions: []
YAML);
        $loader->sync($this->tmpFile);

        $this->assertSame(2, EvalCase::where('dataset_id', $id)->count());
    }

    public function test_sync_preserves_cases_with_existing_results(): void
    {
        $this->writeYaml(<<<YAML
slug: retain
name: Retain
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions: []
  - slug: c2
    prompt: Bye
    stub_response: Goodbye
    assertions: []
YAML);
        $loader = new Loader();
        $datasetId = $loader->sync($this->tmpFile);
        $c1 = EvalCase::where('dataset_id', $datasetId)->where('slug', 'c1')->firstOrFail();
        $c2 = EvalCase::where('dataset_id', $datasetId)->where('slug', 'c2')->firstOrFail();

        $run = EvalRun::create([
            'dataset_id' => $datasetId,
            'started_at' => now(),
            'finished_at' => now(),
            'cases_total' => 2, 'cases_passed' => 2, 'cases_failed' => 0,
            'score_pct' => 100.00, 'trigger' => 'manual',
        ]);
        EvalResult::create([
            'run_id' => $run->id, 'case_id' => $c1->id,
            'assertion_index' => 0, 'assertion_type' => 'contains_text',
            'passed' => true, 'actual_response' => 'Hello', 'reason' => 'ok',
        ]);
        EvalResult::create([
            'run_id' => $run->id, 'case_id' => $c2->id,
            'assertion_index' => 0, 'assertion_type' => 'contains_text',
            'passed' => true, 'actual_response' => 'Goodbye', 'reason' => 'ok',
        ]);

        // Edit YAML: change c1 stub, drop c2 entirely.
        $this->writeYaml(<<<YAML
slug: retain
name: Retain
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hi there
    assertions: []
YAML);
        $loader->sync($this->tmpFile);

        // c1 updated in place, c2 kept (has results) as orphan.
        $this->assertSame('Hi there', EvalCase::find($c1->id)->stub_response);
        $this->assertNotNull(EvalCase::find($c2->id), 'c2 with results must not be deleted');
    }

    public function test_sync_prunes_cases_without_results_when_removed_from_yaml(): void
    {
        $this->writeYaml(<<<YAML
slug: prune
name: Prune
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions: []
  - slug: c2
    prompt: Bye
    stub_response: Goodbye
    assertions: []
YAML);
        $loader = new Loader();
        $datasetId = $loader->sync($this->tmpFile);
        $c2Id = EvalCase::where('dataset_id', $datasetId)->where('slug', 'c2')->value('id');

        $this->writeYaml(<<<YAML
slug: prune
name: Prune
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello
    assertions: []
YAML);
        $loader->sync($this->tmpFile);

        $this->assertNull(EvalCase::find($c2Id), 'c2 with no results should be pruned');
    }

    public function test_sync_throws_on_invalid_yaml(): void
    {
        $this->writeYaml("slug: [unterminated");
        $this->expectException(\RuntimeException::class);
        (new Loader())->sync($this->tmpFile);
    }

    public function test_sync_throws_runtime_exception_on_empty_file(): void
    {
        $this->writeYaml('');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty or not a YAML mapping');
        (new Loader())->sync($this->tmpFile);
    }

    public function test_sync_throws_when_cases_is_not_a_list(): void
    {
        $this->writeYaml(<<<YAML
slug: scalar-cases
name: Scalar cases
vertical: hotel
cases: not-an-array
YAML);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'cases' must be a list");
        (new Loader())->sync($this->tmpFile);
    }
}
