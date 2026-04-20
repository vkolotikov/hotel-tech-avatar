# Sentry

**Status:** `planned` (wiring is a Phase 0 DoD item — see `docs/phases/phase-0-telemetry.md`)
**Last verified:** 2026-04-20
**Official docs:** https://docs.sentry.io

## What we use it for

Error tracking — backend (Laravel) and mobile (Expo / React Native). Required by Phase 0 exit criteria per `CLAUDE.md` §Phase 0: "Sentry is capturing errors in both backend and mobile."

## Endpoints this project calls

Sentry SDKs handle the transport — we do not call endpoints directly. Envelope uploads go to `https://{project_id}.ingest.sentry.io/api/{project_id}/envelope/`.

- **Backend**: `sentry/sentry-laravel` Composer package
- **Mobile**: `@sentry/react-native` npm package (works with Expo)

## Authentication

- DSN (not a secret in the traditional sense — public-facing, project-scoped)
- Config: `SENTRY_LARAVEL_DSN` (backend), `SENTRY_MOBILE_DSN` (Expo). Distinct DSNs per surface.
- PII scrubbing enabled by default — opt-in only for fields we've explicitly reviewed.

## Error handling expectations

- Sentry's own SDK swallows its errors; our app continues normally on Sentry outages.
- Sampling: 100% for errors, TBD for transactions (likely 10–20% in production).
- Release tagging required — tied to git SHA via CI.

## Cost and quota notes

- Pricing: https://sentry.io/pricing — event volume-based.
- Drop event rate is the tuning knob. Never drop Errors; sample Transactions/Profiling aggressively.

## Project-specific notes

- **Must not capture user health content.** Before-send hook must scrub message bodies, uploaded file names, and any prompt/response fields. Wellness-vertical traces that leak PHI are a compliance incident.
- Source maps uploaded via Sentry Wizard in CI for the mobile app.
- Alert routing: critical errors page on-call; verification-pipeline failures get a separate channel (noisy, not pageable).

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
