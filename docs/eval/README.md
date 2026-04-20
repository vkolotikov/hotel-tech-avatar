# Evaluation harness

Skeleton introduced in Phase 0. The harness is the quality contract per
`CLAUDE.md` §Hard rules #6 — every change to prompts, retrieval, tools,
verification, or the knowledge base must run this harness and must not
regress scores.

## Layout

- `datasets/{vertical}/{avatar?}/{slug}.yaml` — one dataset per file,
  git-reviewable. Synced into Postgres on each `eval:run`.
- `rubrics/` — authored rubrics for Phase 1+ LLM-as-judge assertions.

## Running

```bash
php artisan eval:run                        # every dataset
php artisan eval:run --dataset=hotel-smoke  # single dataset by slug
php artisan eval:list-datasets              # registered datasets
```

Results land in `eval_runs` + `eval_results`. Inspect with:

```sql
SELECT r.id, d.slug, r.cases_total, r.cases_passed, r.score_pct, r.finished_at
FROM eval_runs r JOIN eval_datasets d ON d.id = r.dataset_id
ORDER BY r.id DESC LIMIT 10;
```

## Adding a dataset

1. Create `docs/eval/datasets/{vertical}/{avatar?}/{slug}.yaml`.
2. Follow the shape in `docs/eval/datasets/hotel/smoke/smoke.yaml`.
3. Run `php artisan eval:run --dataset={slug}` to sync + execute.

## Adding an assertion type

1. Implement `App\Eval\Assertion\Assertion` in a new class under
   `app/Eval/Assertion/`.
2. Register the class in `App\Eval\AssertionFactory::TYPES`.
3. Add a case in `docs/eval/datasets/hotel/smoke/smoke.yaml` (or a
   dedicated smoke dataset) exercising it.
4. `php artisan eval:run` — zero runner code changes required.

## What's **not** here yet

- Authored wellness datasets (Phase 1 per avatar).
- LLM-as-judge assertions (Phase 1+, gated on ZDR).
- CI regression gate (Phase 2).
- Browsing UI (Filament, Phase 3).
