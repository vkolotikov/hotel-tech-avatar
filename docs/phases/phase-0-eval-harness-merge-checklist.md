# Phase 0 — Eval harness merge checklist

## Automated

- [x] `php artisan test` — all green (includes EvalSchemaTest, AssertionsTest, LoaderTest, RunnerTest, EvalRunCommandTest, ScheduleTest). Verified 2026-04-20: 73 passed, 260 assertions.
- [x] `php artisan test --filter=HotelSpaRegressionTest` — hotel unchanged. Verified 2026-04-20: 3 passed, 24 assertions.
- [x] `php artisan migrate:fresh --seed` then `php artisan eval:run --dataset=hotel-smoke` — exits 0, reports 2/2 passed. Verified 2026-04-20: `[run #1] hotel-smoke: 2/2 passed (100.00%)`, exit 0.
- [x] `php artisan schedule:list` — shows `eval:run --trigger=scheduled` at `0 3 * * *`. Verified 2026-04-20.

## Manual

- [x] `docs/eval/README.md` reads cleanly standalone.
- [x] `docs/adr/2026-04-20-phase-0-eval-harness.md` exists and records the seven decisions.
- [x] `docs/eval/datasets/hotel/smoke/smoke.yaml` present.

## DB smoke-test

```sql
SELECT slug, vertical_slug, avatar_slug FROM eval_datasets;
-- expect one row: hotel-smoke / hotel / hotel-concierge

SELECT r.id, d.slug, r.cases_total, r.cases_passed, r.score_pct, r.finished_at
FROM eval_runs r JOIN eval_datasets d ON d.id = r.dataset_id
ORDER BY r.id DESC LIMIT 5;
-- expect at least one row with score_pct = 100.00
```

## Not in this phase (verify no one added them)

- [x] No CI step fails the build on eval score. (No `.github/workflows/` at all.)
- [x] No wellness datasets present under `docs/eval/datasets/wellness/`. (Only `.gitkeep`.)
- [x] No LLM-as-judge assertion class. (Six deterministic classes: ContainsText, DoesNotContain, MatchesRegex, CitationCountAtLeast, RedFlagTriggered, VerificationStatus.)
- [x] No Filament eval admin. (No `app/Filament/` at all.)
