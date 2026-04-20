<?php

namespace App\Eval;

use App\Eval\Assertion\AssertionResult;
use App\Models\EvalCase;
use App\Models\EvalDataset;
use App\Models\EvalResult;
use App\Models\EvalRun;
use Illuminate\Support\Carbon;

final class Runner
{
    public function runDataset(int $datasetId, string $trigger): int
    {
        $dataset = EvalDataset::findOrFail($datasetId);

        $run = EvalRun::create([
            'dataset_id' => $dataset->id,
            'started_at' => Carbon::now(),
            'trigger' => $trigger,
            'cases_total' => 0,
            'cases_passed' => 0,
            'cases_failed' => 0,
        ]);

        $passed = 0;
        $failed = 0;
        $total = 0;

        foreach ($dataset->cases as $case) {
            $total++;
            $casePassed = $this->runCase($run->id, $case);
            if ($casePassed) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $score = $total === 0 ? null : round($passed / $total * 100, 2);

        $run->update([
            'finished_at' => Carbon::now(),
            'cases_total' => $total,
            'cases_passed' => $passed,
            'cases_failed' => $failed,
            'score_pct' => $score,
        ]);

        return $run->id;
    }

    private function runCase(int $runId, EvalCase $case): bool
    {
        $response = $this->resolveResponse($case);
        $context = $case->context_json ?? [];
        $allPassed = true;

        foreach (($case->assertions_json ?? []) as $i => $config) {
            $result = $this->evaluateOne($config, $response, $context);
            if (!$result->passed) {
                $allPassed = false;
            }

            EvalResult::create([
                'run_id' => $runId,
                'case_id' => $case->id,
                'assertion_index' => $i,
                'assertion_type' => $config['type'] ?? 'unknown',
                'passed' => $result->passed,
                'actual_response' => $result->passed ? null : $response,
                'reason' => $result->reason,
            ]);
        }

        return $allPassed && !empty($case->assertions_json);
    }

    private function resolveResponse(EvalCase $case): string
    {
        return $case->stub_response ?? '';
    }

    private function evaluateOne(array $config, string $response, array $context): AssertionResult
    {
        try {
            return AssertionFactory::make($config)->evaluate($response, $context);
        } catch (\Throwable $e) {
            return AssertionResult::fail('assertion error: ' . $e->getMessage());
        }
    }
}
