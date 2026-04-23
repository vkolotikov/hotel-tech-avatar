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
    private ?string $currentMode = null;

    public function __construct(private readonly ?LiveResolver $liveResolver = null) {}

    public function runDataset(int $datasetId, string $trigger, ?string $modeOverride = null): int
    {
        $dataset = EvalDataset::findOrFail($datasetId);

        $datasetMode = is_array($dataset->mode_json) ? ($dataset->mode_json['mode'] ?? null) : null;
        $this->currentMode = $modeOverride ?? $datasetMode ?? 'stubbed';

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
        $resolvedResponse = $this->resolveResponse($case);

        $context = $case->context_json ?? [];
        if ($resolvedResponse instanceof ResolvedResponse) {
            $context['red_flag_triggered'] = $resolvedResponse->red_flag_triggered;
            $context['red_flag_id'] = $resolvedResponse->red_flag_id;
            $context['handoff_target'] = $resolvedResponse->handoff_target;
            $responseText = $resolvedResponse->text;
        } else {
            $context['red_flag_triggered'] = false;
            $responseText = $resolvedResponse;
        }

        $allPassed = true;

        foreach (($case->assertions_json ?? []) as $i => $config) {
            $result = $this->evaluateOne($config, $responseText, $context);
            if (!$result->passed) {
                $allPassed = false;
            }

            EvalResult::create([
                'run_id' => $runId,
                'case_id' => $case->id,
                'assertion_index' => $i,
                'assertion_type' => $config['type'] ?? 'unknown',
                'passed' => $result->passed,
                'actual_response' => $result->passed ? null : $responseText,
                'reason' => $result->reason,
            ]);
        }

        return $allPassed && !empty($case->assertions_json);
    }

    private function resolveResponse(EvalCase $case): string | ResolvedResponse
    {
        if ($this->currentMode === 'live' && $this->liveResolver) {
            // EvalDataset stores avatar_slug as a plain string; look up the
            // Agent by slug. Missing or unpublished avatars fall back to the
            // case's stub_response so the run finishes with a concrete result.
            $slug = $case->dataset?->avatar_slug;
            $agent = $slug
                ? \App\Models\Agent::where('slug', $slug)->first()
                : null;

            if (!$agent) {
                return $case->stub_response ?? '';
            }

            return $this->liveResolver->resolve($case, $agent);
        }

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
