# ADR — Phase 0 Eval Harness

**Date:** 2026-04-20
**Status:** Accepted
**Authors:** platform

## Context

`CLAUDE.md` §Hard rules #6 names the eval harness "the quality contract":
every change to prompts, retrieval, tools, verification, or the knowledge
base must run it without regressing scores. Phase 0 definitions of done
require a runnable skeleton before Phase 1 begins generation work.

## Decisions

1. **YAML on disk, synced to Postgres.** Datasets live under
   `docs/eval/datasets/{vertical}/{avatar?}/{slug}.yaml` and are upserted
   into `eval_datasets` + `eval_cases` on every `eval:run`. Rationale:
   datasets are content that belongs in git diffs alongside the prompts
   they measure. DB-only authoring loses review traceability.

2. **Four tables, eval-namespaced, no cross-FKs to production.**
   `eval_datasets`, `eval_cases`, `eval_runs`, `eval_results`. Eval runs
   never read or write real chat traffic tables. This keeps the harness
   safe to run against production DB snapshots.

3. **Deterministic assertions only in Phase 0.** Six types: contains_text,
   does_not_contain, matches_regex, citation_count_at_least,
   red_flag_triggered, verification_status. LLM-as-judge assertions
   deferred to Phase 1+ (require ZDR + cross-model critic).

4. **Skeleton assertions for pipeline-dependent behaviour.**
   `red_flag_triggered` and `verification_status` read from the case's
   `context` hash in Phase 0; real integration lands with the verification
   pipeline. `citation_count_at_least` uses a naive regex; real citation
   extraction lands with the verifier.

5. **`stub_response` instead of real LLM invocation.** Phase 0's runner
   reads the YAML-authored `stub_response` verbatim. Phase 1 makes it an
   optional fallback: if absent, the runner invokes the real `LlmClient`.
   This is forward-compatible, not technical debt.

6. **Scheduler via `bootstrap/app.php` `withSchedule()`.** Laravel 13's
   minimal kernel removes `app/Console/Kernel.php`; the `withSchedule`
   closure is the canonical location. Nightly run at 03:00 UTC with
   `--trigger=scheduled`, `withoutOverlapping()`, `onOneServer()`.

7. **No CI gate yet.** `eval:run` always exits 0 in Phase 0 regardless of
   score. A fail-build-on-regression step lands in Phase 2 once baseline
   scores exist to regress against.

## Consequences

- Adding a new assertion type is a one-file change plus a factory
  registration line — no runner edits.
- Adding a dataset is a YAML commit; `eval:run --dataset={slug}` runs it.
- Phase 1 owns: authored Nora dataset, LlmClient-backed responses when
  `stub_response` is absent, real citation extraction.
- Phase 2 owns: CI gate, run comparison dashboard.
- Phase 3 owns: Filament admin for run browsing.

## Alternatives considered

- **DB-first authoring (Filament CRUD for cases):** rejected —
  datasets need git review.
- **JSON datasets:** rejected — multi-line expected-text fields are
  unreadable in JSON.
- **Pytest-style in-code cases:** rejected — non-engineer reviewers (the
  domain-advisor role in spec §10) must be able to read and edit cases
  without touching PHP.

## Implementation notes

- `bootstrap/app.php` required `->withCommands()` (alongside the new
  `->withSchedule()`) so Laravel auto-discovers `app/Console/Commands/`
  classes. `withRouting(commands: routes/console.php)` alone only wires
  the closures in `routes/console.php`, not class-based commands.
- `Laravel\Foundation\Configuration\ApplicationBuilder::withSchedule()`
  registers its callback via `Artisan::starting()` — the callback only
  fires when the Artisan console boots. HTTP-kernel tests that want to
  inspect the schedule must trigger Artisan first (e.g.
  `Artisan::call('list')`) before resolving `Schedule::class`.
- `Eloquent\Model::save()` calls `syncOriginal()`, so after
  `updateOrCreate` the Eloquent original bag reflects the just-saved
  state. The Loader captures pre-update `source_hash` via an explicit
  `where('slug', …)->first()` query inside the transaction to detect
  real changes.
