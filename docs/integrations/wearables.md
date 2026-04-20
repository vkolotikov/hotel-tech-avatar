# Wearables — HealthKit, Google Health Connect, Oura, Whoop, Garmin, Fitbit

**Status:** `planned` — wellness vertical biometric inputs
**Last verified:** 2026-04-20
**Official docs:**
- Apple HealthKit: https://developer.apple.com/documentation/healthkit
- Google Health Connect: https://developer.android.com/health-and-fitness/guides/health-connect
- Oura API v2: https://cloud.ouraring.com/v2/docs
- Whoop API: https://developer.whoop.com/docs
- Garmin Health API: https://developer.garmin.com/gc-developer-program/health-api/
- Fitbit Web API: https://dev.fitbit.com/build/reference/web-api/

## What we use it for

Sleep, HRV, activity, and recovery data — context for Luna (sleep), Zen (mindfulness), Axel (fitness/longevity), and cross-cutting for Dr. Integra. Users connect wearables; the app reads daily summaries (not raw firehose streams) to personalise advice. Raw samples land in `wearable_samples` (deferred to Phase 5 per ADR).

## Endpoints this project calls

_None yet._ Wearables fall into two integration styles:

**On-device SDKs (no server round-trip):**
- **HealthKit** (iOS): `react-native-health` or Expo's HealthKit module. Data never leaves the device without user consent; we request typed samples (e.g., `HKQuantityTypeIdentifierStepCount`, `HKCategoryTypeIdentifierSleepAnalysis`) and post daily summaries to our API.
- **Health Connect** (Android): `react-native-health-connect`. Permission model mirrors HealthKit.

**Cloud APIs (OAuth'd server-to-server):**
- **Oura**: `GET /v2/usercollection/daily_sleep`, `/daily_activity`, `/daily_readiness` — OAuth 2.0
- **Whoop**: `GET /v1/cycle`, `/v1/recovery`, `/v1/sleep` — OAuth 2.0
- **Garmin Health API**: webhook-push model — Garmin POSTs summaries to our endpoint as new data arrives; requires partnership approval.
- **Fitbit**: `GET /1.2/user/-/sleep/date/{date}.json` and related — OAuth 2.0, subscription API for push

## Authentication

- **HealthKit / Health Connect**: per-user on-device permission prompt, no secrets
- **Oura, Whoop, Fitbit**: OAuth 2.0 — client ID + secret per app; per-user refresh tokens stored server-side
- **Garmin**: partnership + consumer key/secret
- Env vars: `OURA_CLIENT_ID/SECRET`, `WHOOP_CLIENT_ID/SECRET`, `FITBIT_CLIENT_ID/SECRET`, `GARMIN_CONSUMER_KEY/SECRET`
- Per-user OAuth tokens will live in a `user_wearable_connections` table (Phase 5 schema)

## Error handling expectations

_To be filled in by the first implementer._ Key concerns: refresh-token expiry, user-revoked permissions (must gracefully degrade, not crash the chat), provider-side rate limits, time-zone handling (wearable "today" ≠ server "today").

## Cost and quota notes

- HealthKit / Health Connect: free
- Oura, Whoop, Fitbit: free developer tiers are generous for our user volume
- Garmin: partnership tier negotiation required

## Project-specific notes

- **Privacy is the hardest part, not the technical integration.** Every wearable grant is a per-scope, per-user consent that must be revocable without data loss; consent state lives in `users.consent_json`.
- **Don't ingest raw samples by default.** Daily summaries are enough for v1.0; raw samples are expensive to store and rarely actionable. `wearable_samples` schema (Phase 5) covers the minority case where avatars need finer-grained data.
- `user_profiles.wearables_connected` jsonb is the current-truth flag for "what did this user connect"; tokens and timestamps live in the (Phase 5) `user_wearable_connections` table.
- HealthKit and Health Connect never expose raw data to our server without the user authoring a sync action — the daily summary post is what we store.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
