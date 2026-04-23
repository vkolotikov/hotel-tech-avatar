# Phase 1 — Eval harness runbook

Nora's 17-case golden dataset already exists at
[docs/eval/datasets/wellness/nora/nora.yaml](../eval/datasets/wellness/nora/nora.yaml).
This is the runbook for executing it and interpreting results.

## First run

From the Laravel Cloud shell (or locally):

```bash
# All datasets (hotel smoke + wellness/nora)
php artisan eval:run

# Just Nora
php artisan eval:run --dataset=nora-golden

# Mark this as a scheduled/CI run (affects metadata only)
php artisan eval:run --dataset=nora-golden --trigger=ci
```

The command:
1. Walks `docs/eval/datasets/**/*.yaml`.
2. Upserts each into `eval_datasets` + `eval_cases`.
3. For each dataset, creates an `eval_runs` row and runs every case through `App\Eval\Runner`, which calls `App\Eval\LiveResolver::resolve(case, agent)` to produce a real model response via `LlmClient`.
4. Scores each case via the assertions (`expected_contains`, `expected_not_contains`, `citations_required`, `handoff_expected`) and writes `eval_results` rows.
5. Prints a one-line summary: `[run #N] nora-golden: X/17 passed (Y%)`.

## Phase-1 exit criteria

Per [phase-1-kickoff.md](phase-1-kickoff.md) and the dataset's own header:

| Metric | Target |
|---|---|
| Overall pass rate on `nora-golden` | ≥ 85% |
| Pass rate on cases with `safety_critical: true` | 100% (4 cases) |
| Hallucinated citations | 0 (manual spot-check cases flagged `citations_required: true`) |

**Do not promote a new system prompt to `active_prompt_version` unless both targets are met.**

## Inspecting results

```sql
-- Last ten runs, newest first
SELECT r.id, d.slug, r.cases_total, r.cases_passed, r.score_pct,
       r.trigger, r.started_at, r.finished_at
  FROM eval_runs r
  JOIN eval_datasets d ON d.id = r.dataset_id
 ORDER BY r.id DESC
 LIMIT 10;

-- Failed cases in the most recent Nora run, with the failure details
SELECT c.slug, res.passed, res.assertion_log, res.actual_output
  FROM eval_results res
  JOIN eval_cases c ON c.id = res.case_id
  JOIN eval_runs  r ON r.id = res.run_id
 WHERE r.id = (SELECT id FROM eval_runs ORDER BY id DESC LIMIT 1)
   AND res.passed = false
 ORDER BY c.slug;
```

## When a case fails

Two typical patterns:

1. **Brittle `expected_contains`** — the model answered correctly but used a synonym the dataset didn't anticipate. Fix the dataset: widen the expected list or reframe the assertion.
2. **Actual regression** — the model answer is wrong, hallucinated, or missed a red-flag trigger. Fix the *system prompt*, re-run, iterate. Do NOT loosen the dataset to make a regression pass.

The distinction matters because the eval is the regression gate per CLAUDE.md Hard Rule #6. Drifting the dataset down to match a degraded model defeats the purpose.

## What's coming (not yet wired)

- **LLM-as-judge rubrics** in `docs/eval/rubrics/` are authored for Phase-1 but not yet consumed by the Runner — all current scoring is string-match. This is a deliberate v1 choice; the judge comes in Phase 6 (v1.1).
- **Per-avatar latency metrics** in the run summary — target from the kickoff brief (p95 first-token ≤ 2.5s). Would pull from `llm_calls` rows tagged with the run's `trace_id`.
- **HTML report** — right now results only live in Postgres. A small artisan command that dumps a Markdown summary into `storage/eval-reports/` would be a nice addition before we onboard other contributors to prompt authoring.

## Next steps when you run this

1. Execute `eval:run --dataset=nora-golden` and paste the score line back to me.
2. If anything failed, share the `assertion_log` of one or two failures — we'll decide per-case whether it's prompt work or dataset work.
3. Once Nora clears 85% overall AND 100% on `safety_critical`, snapshot the current prompt as a version (Admin → Prompt Versions → "Save current as new version" with a note like "cleared v1 eval").

That snapshot becomes the Phase-1 launch baseline for Nora.
