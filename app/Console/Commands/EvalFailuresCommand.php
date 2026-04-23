<?php

namespace App\Console\Commands;

use App\Models\EvalCase;
use App\Models\EvalDataset;
use App\Models\EvalResult;
use App\Models\EvalRun;
use Illuminate\Console\Command;

/**
 * Shows every failing case from the most recent run of a dataset.
 * Prints the prompt, the actual model response, and which assertions
 * missed + why. Ideal for a post-mortem after eval:run reports a low
 * score.
 *
 *   php artisan eval:failures integra-golden
 *   php artisan eval:failures integra-golden --run=47
 *   php artisan eval:failures                           # every dataset's latest run
 */
class EvalFailuresCommand extends Command
{
    protected $signature = 'eval:failures
        {dataset? : Dataset slug; omit to scan every dataset}
        {--run= : Specific eval_runs.id to inspect (default: latest for each dataset)}';

    protected $description = 'List failing cases from the most recent eval run with full diagnostics.';

    public function handle(): int
    {
        $datasetSlug = $this->argument('dataset');
        $runIdOpt    = $this->option('run');

        $datasets = $datasetSlug
            ? EvalDataset::where('slug', $datasetSlug)->get()
            : EvalDataset::orderBy('slug')->get();

        if ($datasets->isEmpty()) {
            $this->error($datasetSlug ? "dataset '{$datasetSlug}' not found" : 'no datasets found');
            return self::FAILURE;
        }

        foreach ($datasets as $dataset) {
            $run = $runIdOpt
                ? EvalRun::where('id', $runIdOpt)->where('dataset_id', $dataset->id)->first()
                : EvalRun::where('dataset_id', $dataset->id)->orderByDesc('id')->first();

            if (!$run) {
                $this->warn("  {$dataset->slug}: no runs yet");
                $this->newLine();
                continue;
            }

            $this->line(sprintf(
                '<options=bold;fg=cyan>── %s</> <fg=gray>(run #%d, %d/%d passed, %s%%)</>',
                $dataset->slug,
                $run->id,
                $run->cases_passed,
                $run->cases_total,
                $run->score_pct ?? 'n/a',
            ));

            $failingCaseIds = EvalResult::query()
                ->where('run_id', $run->id)
                ->where('passed', false)
                ->pluck('case_id')
                ->unique()
                ->values();

            if ($failingCaseIds->isEmpty()) {
                $this->line('  <fg=green>no failures</>');
                $this->newLine();
                continue;
            }

            $cases = EvalCase::whereIn('id', $failingCaseIds)
                ->orderBy('slug')
                ->get()
                ->keyBy('id');

            foreach ($failingCaseIds as $caseId) {
                $case = $cases->get($caseId);
                if (!$case) continue;

                $this->line("  <options=bold>✗ {$case->slug}</>");
                $this->line('    <fg=gray>prompt:</>   ' . $this->truncate($case->prompt, 160));

                $results = EvalResult::where('run_id', $run->id)
                    ->where('case_id', $case->id)
                    ->orderBy('assertion_index')
                    ->get();

                // actual_response only lives on failing assertions; grab
                // the first non-null we can find for the case.
                $actual = $results->firstWhere(
                    fn ($r) => $r->actual_response !== null
                )?->actual_response
                    ?? '(not recorded — every assertion passed? strange)';
                $this->line('    <fg=gray>response:</> ' . $this->truncate($actual, 280));

                foreach ($results as $r) {
                    if ($r->passed) continue;
                    $this->line(sprintf(
                        '    <fg=red>- #%d %s</> — %s',
                        $r->assertion_index,
                        $r->assertion_type,
                        $r->reason ?: '(no reason)',
                    ));
                }
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    private function truncate(?string $s, int $n): string
    {
        $s = (string) $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
    }
}
