# Phase 0 — exit criteria status

**Generated:** 2026-04-23
**Source of truth for the gate:** [CLAUDE.md](../../CLAUDE.md) § "Phase 0 — definitions of done"

This is a snapshot of where each Phase-0 exit item stands. Keep it honest — update after any meaningful status change.

## Items

| Item | Status | Notes |
|---|---|---|
| Mobile shell: Expo project, builds, Sanctum auth round-trip | ✅ Done | `mobile/` builds on Android; Sanctum login → `/me` round-trips. |
| Sentry capturing errors (backend) | 🟡 Code wired, awaiting DSN | `sentry/sentry-laravel` is installed, `config/sentry.php` published, and `bootstrap/app.php` calls `\Sentry\Laravel\Integration::handles()`. Set `SENTRY_LARAVEL_DSN` in Laravel Cloud secrets to activate. |
| Sentry capturing errors (mobile) | 🟡 Code wired, awaiting DSN + rebuild | `@sentry/react-native` added to `mobile/package.json`; `mobile/src/sentry.ts` initialises only when `EXPO_PUBLIC_SENTRY_DSN` is set. Native module — requires `npm install` + `npx expo run:android` rebuild once before it captures anything. |
| Langfuse receiving a trace on at least one real LLM call | 🟡 Code wired, awaiting keys | `LangfuseTracer` already implemented; `LlmServiceProvider` picks it over `NullTracer` when `LANGFUSE_ENABLED=true`. Set `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY` + flip `LANGFUSE_ENABLED=true` in Laravel Cloud. |
| LlmClient abstraction (prereq for Langfuse) | ✅ Done | `App\Services\Llm\LlmClient` with `ProviderInterface` (`OpenAiProvider`) + `TracerInterface` (`LangfuseTracer` / `NullTracer`) + `llm_calls` ledger already consumed by `GenerationService`, `EmbeddingService`, and `app/Eval/LiveResolver`. |
| PostHog | ⏸️ Correctly deferred | Waits for Phase 3 per spec. |
| pgvector extension available on the Laravel Cloud Postgres instance | ✅ Implicit | Migration [2026_04_19_000011_enable_pgvector.php](../../database/migrations/2026_04_19_000011_enable_pgvector.php) runs `CREATE EXTENSION IF NOT EXISTS vector` on every deploy; all recent deploys have succeeded, so the extension is present. For a positive smoke-test, run [scripts/check-pgvector.sql](../../scripts/check-pgvector.sql) via `psql $DATABASE_URL -f scripts/check-pgvector.sql`. |
| ZDR confirmation recorded | 🟡 In flight | Request filed 2026-04-19; see [docs/compliance/openai-zdr.md](../compliance/openai-zdr.md). |
| Data-model extensions (vertical + agent config + prompt versions + citations + verification events + token usage + subscriptions) | ✅ Done | All migrations landed (see `database/migrations/2026_04_19_*.php`). |
| Hotel vertical smoke test (no regressions) | 🟡 Pending manual pass | Existing SPA at `/spa` still serving; no automated check yet. |
| Eval harness skeleton | ✅ Scaffold present | `docs/eval/` + backing migrations for datasets, cases, runs, results. |
| Monorepo layout clarified | ✅ Done | `backend/ app/`, `mobile/`, `frontend/`, `docs/`, `public/spa/`, `public/admin/`. ml-service not yet scaffolded (optional until first Python need). |

## Remaining work — ordered by what unblocks the most

### 1. Sentry (backend + mobile)
Code is already in place, both backend and mobile. All that's left is provisioning:

1. Create a Sentry organisation + two projects: `avatar-backend` (PHP/Laravel) and `wellnessai-mobile` (React Native).
2. Laravel Cloud secrets: set `SENTRY_LARAVEL_DSN` to the backend DSN; optional: `SENTRY_TRACES_SAMPLE_RATE`.
3. `mobile/.env`: set `EXPO_PUBLIC_SENTRY_DSN` to the mobile DSN.
4. Mobile only: `cd mobile && npm install` then `npx expo run:android` to rebuild the dev client with the new native module.

### 2. Langfuse (LLM observability)
Code is in place. All that's left is provisioning:

1. Create a Langfuse project (self-hosted or cloud.langfuse.com).
2. Laravel Cloud secrets: `LANGFUSE_ENABLED=true`, `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`. Host stays default unless self-hosted.
3. Any LLM call that flows through `LlmClient` (i.e. all current generation paths) will appear in Langfuse.

### 3. pgvector availability on Laravel Cloud
Run the smoke script once from the Cloud shell:

```bash
psql $DATABASE_URL -f scripts/check-pgvector.sql
```

Script creates a throwaway table, inserts one embedding, queries it back, then drops the table. If it fails because `pgvector` isn't available on the current tier, raise immediately — it's a Phase-1 blocker (retrieval).

### 4. ZDR formal confirmation
Nudge OpenAI contact if no response by 2026-05-03. Gate doesn't bite until Phase 3, but the clock is already running.

### 5. Hotel smoke test
Manual pass of the five existing hotel flows (concierge, chef, coordinator, spa, in-SPA admin). If something has regressed from the wellness-vertical work, catch it before Phase 1.

## Exit criteria status

**Overall:** Phase 0 is **code-complete**. All data-model, platform, mobile-shell, and observability-scaffold deliverables are in the repo. What's left is:

- Provisioning external services (Sentry orgs, Langfuse project, ZDR reply).
- Dropping the resulting secrets into Laravel Cloud + `mobile/.env`.
- A one-time mobile rebuild so the Sentry native module is linked.

No hard blockers for starting Phase 1 work on the wellness vertical. Complete items 1-2 above before the first real wellness-user data arrives (Phase 3).
