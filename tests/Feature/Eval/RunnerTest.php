<?php

namespace Tests\Feature\Eval;

use App\Eval\Loader;
use App\Eval\Runner;
use App\Models\EvalResult;
use App\Models\EvalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir() . '/eval_runner_' . uniqid() . '.yaml';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) unlink($this->tmpFile);
        parent::tearDown();
    }

    public function test_runner_executes_every_case_and_computes_score(): void
    {
        file_put_contents($this->tmpFile, <<<YAML
slug: runner-demo
name: Runner demo
vertical: hotel
cases:
  - slug: pass-case
    prompt: Hi
    stub_response: Hello welcome
    assertions:
      - type: contains_text
        value: welcome
  - slug: fail-case
    prompt: Hi
    stub_response: Hello
    assertions:
      - type: contains_text
        value: missing-word
YAML);
        $datasetId = (new Loader())->sync($this->tmpFile);

        $runId = (new Runner())->runDataset($datasetId, 'manual');

        $run = EvalRun::findOrFail($runId);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, $run->cases_total);
        $this->assertSame(1, $run->cases_passed);
        $this->assertSame(1, $run->cases_failed);
        $this->assertEquals('50.00', (string) $run->score_pct);
        $this->assertSame(2, EvalResult::where('run_id', $runId)->count());
    }

    public function test_failed_assertion_records_actual_response_and_reason(): void
    {
        file_put_contents($this->tmpFile, <<<YAML
slug: fail-detail
name: Fail detail
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Goodbye
    assertions:
      - type: contains_text
        value: Hello
YAML);
        $datasetId = (new Loader())->sync($this->tmpFile);

        $runId = (new Runner())->runDataset($datasetId, 'manual');

        $result = EvalResult::where('run_id', $runId)->firstOrFail();
        $this->assertFalse($result->passed);
        $this->assertSame('Goodbye', $result->actual_response);
        $this->assertStringContainsString('Hello', $result->reason);
    }

    public function test_assertion_exception_marks_single_assertion_failed_run_continues(): void
    {
        file_put_contents($this->tmpFile, <<<YAML
slug: boom
name: Boom
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: anything
    assertions:
      - type: matches_regex
        pattern: /unterminated
      - type: contains_text
        value: anything
YAML);
        $datasetId = (new Loader())->sync($this->tmpFile);

        $runId = (new Runner())->runDataset($datasetId, 'manual');

        $run = EvalRun::findOrFail($runId);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->cases_passed);
        $this->assertSame(1, $run->cases_failed);
        $this->assertSame(2, EvalResult::where('run_id', $runId)->count());
    }
}
