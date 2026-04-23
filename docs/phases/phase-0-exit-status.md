# Phase 0 — exit criteria status

**Generated:** 2026-04-23
**Source of truth for the gate:** [CLAUDE.md](../../CLAUDE.md) § "Phase 0 — definitions of done"

This is a snapshot of where each Phase-0 exit item stands. Keep it honest — update after any meaningful status change.

## Items

| Item | Status | Notes |
|---|---|---|
| Mobile shell: Expo project, builds, Sanctum auth round-trip | ✅ Done | `mobile/` builds on Android; Sanctum login → `/me` round-trips. |
| Sentry capturing errors (backend + mobile) | 🟠 Not wired | See "Remaining work" below. |
| Langfuse receiving a trace on at least one real LLM call | 🟠 Not wired | Same block as Sentry (needs the `LlmClient` abstraction). |
| PostHog | ⏸️ Correctly deferred | Waits for Phase 3 per spec. |
| pgvector extension available on the Laravel Cloud Postgres instance | ✅ Implicit | Migration [2026_04_19_000011_enable_pgvector.php](../../database/migrations/2026_04_19_000011_enable_pgvector.php) runs `CREATE EXTENSION IF NOT EXISTS vector` on every deploy; all recent deploys have succeeded, so the extension is present. For a positive smoke-test, run [scripts/check-pgvector.sql](../../scripts/check-pgvector.sql) via `psql $DATABASE_URL -f scripts/check-pgvector.sql`. |
| ZDR confirmation recorded | 🟡 In flight | Request filed 2026-04-19; see [docs/compliance/openai-zdr.md](../compliance/openai-zdr.md). |
| Data-model extensions (vertical + agent config + prompt versions + citations + verification events + token usage + subscriptions) | ✅ Done | All migrations landed (see `database/migrations/2026_04_19_*.php`). |
| Hotel vertical smoke test (no regressions) | 🟡 Pending manual pass | Existing SPA at `/spa` still serving; no automated check yet. |
| Eval harness skeleton | ✅ Scaffold present | `docs/eval/` + backing migrations for datasets, cases, runs, results. |
| Monorepo layout clarified | ✅ Done | `backend/ app/`, `mobile/`, `frontend/`, `docs/`, `public/spa/`, `public/admin/`. ml-service not yet scaffolded (optional until first Python need). |

## Remaining work — ordered by what unblocks the most

### 1. Sentry (backend + mobile)
**Blocker:** needs a Sentry org + project and the DSNs in Laravel Cloud secrets and `mobile/.env`.

Backend:
```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=<backend-dsn>
# then in Laravel Cloud: add SENTRY_LARAVEL_DSN env var
```

Mobile:
```bash
cd mobile
npx expo install @sentry/react-native
# then add EXPO_PUBLIC_SENTRY_DSN to .env
```

Hook the init call in `mobile/App.tsx` inside the top-level provider tree.

### 2. Langfuse (observability for LLM calls)
**Blocker:** a first-pass `LlmClient` service that routes every OpenAI call through one place. Once that exists, tracing is a few lines.

Planned in [phase-0-telemetry-llm-client.md](phase-0-telemetry-llm-client.md). Unlocks the eval harness too.

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

**Overall:** Phase 0 is **substantially done on code** — all data-model, platform, and mobile-shell deliverables are in. Telemetry wiring (Sentry + Langfuse) and the pgvector verification are the remaining items that need a hand-on-keyboard session against production infrastructure.

No hard blockers for starting Phase 1 work on the wellness vertical, but ship these before the first real user data arrives (Phase 3).
