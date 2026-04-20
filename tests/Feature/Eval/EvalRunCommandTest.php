<?php

namespace Tests\Feature\Eval;

use App\Models\EvalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvalRunCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $datasetsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->datasetsRoot = base_path('docs/eval/datasets/hotel/ephemeral-test');
        File::ensureDirectoryExists($this->datasetsRoot);
        File::put($this->datasetsRoot . '/x.yaml', <<<YAML
slug: ephemeral-x
name: Ephemeral X
vertical: hotel
cases:
  - slug: c1
    prompt: Hi
    stub_response: Hello world
    assertions:
      - type: contains_text
        value: Hello
YAML);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->datasetsRoot);
        parent::tearDown();
    }

    public function test_eval_run_executes_a_specific_dataset_by_slug(): void
    {
        $this->artisan('eval:run', ['--dataset' => 'ephemeral-x'])
            ->assertExitCode(0);

        $run = EvalRun::latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(1, $run->cases_total);
        $this->assertSame(1, $run->cases_passed);
    }

    public function test_eval_run_with_no_flags_runs_every_discovered_dataset(): void
    {
        $this->artisan('eval:run')->assertExitCode(0);
        $this->assertGreaterThanOrEqual(1, EvalRun::count());
    }

    public function test_eval_list_datasets_after_run_shows_our_dataset(): void
    {
        $this->artisan('eval:run', ['--dataset' => 'ephemeral-x']);
        $this->artisan('eval:list-datasets')
            ->expectsOutputToContain('ephemeral-x')
            ->assertExitCode(0);
    }
}
