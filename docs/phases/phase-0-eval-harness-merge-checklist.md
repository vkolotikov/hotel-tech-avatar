# Phase 0 — Eval harness merge checklist

## Automated

- [ ] `php artisan test` — all green (includes EvalSchemaTest, AssertionsTest, LoaderTest, RunnerTest, EvalRunCommandTest, ScheduleTest).
- [ ] `php artisan test --filter=HotelSpaRegressionTest` — hotel unchanged.
- [ ] `php artisan migrate:fresh --seed` then `php artisan eval:run --dataset=hotel-smoke` — exits 0, reports 2/2 passed.
- [ ] `php artisan schedule:list` — shows `eval:run --trigger=scheduled` at `0 3 * * *`.

## Manual

- [ ] `docs/eval/README.md` reads cleanly standalone.
- [ ] `docs/adr/2026-04-20-phase-0-eval-harness.md` exists and records the seven decisions.
- [ ] `docs/eval/datasets/hotel/smoke/smoke.yaml` present.

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

- [ ] No CI step fails the build on eval score.
- [ ] No wellness datasets present under `docs/eval/datasets/wellness/`.
- [ ] No LLM-as-judge assertion class.
- [ ] No Filament eval admin.
