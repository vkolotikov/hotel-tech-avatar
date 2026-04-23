<?php

namespace App\Console\Commands;

use App\Eval\Runner;
use App\Models\Agent;
use App\Models\EvalCase;
use App\Models\EvalDataset;
use App\Services\Generation\SystemPromptBuilder;
use Illuminate\Console\Command;

/**
 * Runs ONE eval case end-to-end and prints every diagnostic: the system
 * prompt the model sees, the model's actual reply, and the per-assertion
 * pass/fail breakdown. For when eval:run reports 0% and you need to
 * know why without reading Postgres rows.
 *
 *   php artisan eval:debug-case integra-golden integra_redflag_1
 *   php artisan eval:debug-case nora-golden   nora_basic_1  --mode=live
 *   php artisan eval:debug-case integra-golden integra_basic_1 --mode=stubbed
 */
class EvalDebugCaseCommand extends Command
{
    protected $signature = 'eval:debug-case
        {dataset : Dataset slug (e.g. nora-golden)}
        {case : Case slug (e.g. nora_basic_1)}
        {--mode=live : stubbed|live — what mode to run the case in}';

    protected $description = 'Run a single eval case with full diagnostics printed to the console.';

    public function handle(SystemPromptBuilder $promptBuilder, Runner $runner): int
    {
        $datasetSlug = (string) $this->argument('dataset');
        $caseSlug    = (string) $this->argument('case');
        $mode        = (string) $this->option('mode');

        $dataset = EvalDataset::where('slug', $datasetSlug)->first();
        if (!$dataset) {
            $this->error("dataset '{$datasetSlug}' not found — did you run eval:run first to sync?");
            return self::FAILURE;
        }

        $case = EvalCase::where('dataset_id', $dataset->id)->where('slug', $caseSlug)->first();
        if (!$case) {
            $this->error("case '{$caseSlug}' not found in dataset '{$datasetSlug}'");
            return self::FAILURE;
        }

        $agent = $dataset->avatar_slug
            ? Agent::where('slug', $dataset->avatar_slug)->first()
            : null;

        if (!$agent) {
            $this->error("no agent matches avatar_slug '{$dataset->avatar_slug}'");
            return self::FAILURE;
        }

        $this->line("<options=bold>Agent:</>        {$agent->name} ({$agent->slug})  id={$agent->id}");
        $this->line("<options=bold>Dataset:</>      {$dataset->slug}");
        $this->line("<options=bold>Case:</>         {$case->slug}");
        $this->line("<options=bold>Mode:</>         {$mode}");
        $this->line("<options=bold>User prompt:</>  {$case->prompt}");
        $this->newLine();

        $this->line('<comment>── SYSTEM PROMPT the model receives ──────────────────────────</>');
        $prompt = $promptBuilder->buildForEval($agent);
        $this->line($prompt);
        $this->newLine();

        $this->line('<comment>── FIELDS on agent that built this prompt ────────────────────</>');
        $this->line('system_instructions length: ' . mb_strlen((string) $agent->system_instructions) . ' chars');
        $this->line('persona_json keys:          ' . $this->jsonKeysSummary($agent->persona_json));
        $this->line('scope_json items:           ' . $this->jsonArraySize($agent->scope_json));
        $this->line('red_flag_rules_json items:  ' . $this->jsonArraySize($agent->red_flag_rules_json));
        $this->line('handoff_rules_json items:   ' . $this->jsonArraySize($agent->handoff_rules_json));
        $this->newLine();

        // Run the case through the actual Runner pipeline so we get the
        // same scoring path eval:run uses.
        $this->line('<comment>── RUNNING CASE THROUGH Runner ('.$mode.') ──────────────────</>');
        $runId = $runner->runDataset($dataset->id, 'debug', $mode);

        $result = \App\Models\EvalResult::where('run_id', $runId)
            ->where('case_id', $case->id)
            ->orderBy('assertion_index')
            ->get();

        if ($result->isEmpty()) {
            $this->warn('no assertion results recorded for this case in the run');
            return self::FAILURE;
        }

        $first = $result->first();
        $modelOutput = $first->actual_response ?? '(assertion passed; actual_response was not recorded)';
        $this->line('<options=bold>Model output:</>');
        $this->line($modelOutput);
        $this->newLine();

        $this->line('<options=bold>Assertion results:</>');
        foreach ($result as $r) {
            $mark = $r->passed ? '<fg=green>✓ PASS</>' : '<fg=red>✗ FAIL</>';
            $this->line("  {$mark} #{$r->assertion_index} {$r->assertion_type}" . ($r->reason ? " — {$r->reason}" : ''));
        }
        $this->newLine();

        $allPassed = $result->every(fn ($r) => $r->passed);
        $this->line($allPassed
            ? '<fg=green;options=bold>Case passed.</>'
            : '<fg=red;options=bold>Case failed.</>');

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function jsonKeysSummary(mixed $value): string
    {
        if (!is_array($value) || empty($value)) return '(empty/null)';
        return implode(', ', array_slice(array_keys($value), 0, 8))
            . (count($value) > 8 ? ', …' : '');
    }

    private function jsonArraySize(mixed $value): string
    {
        if (!is_array($value)) return '(null)';
        return (string) count($value) . ' item' . (count($value) === 1 ? '' : 's');
    }
}
