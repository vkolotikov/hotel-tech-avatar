# ADR — Phase 0 Mobile Shell (Expo + Sanctum)

**Date:** 2026-04-20
**Status:** Accepted
**Authors:** platform

## Context

CLAUDE.md §Phase 0 names a "minimal Expo project" that "builds on iOS and
Android simulators, has Sanctum auth wired to the existing Laravel backend,
and successfully round-trips a single authenticated request to a health
endpoint." Nothing else — no chat screen, no onboarding, no avatars, no
voice. Full UI work starts Phase 1.

The backend already has a `SaasAuthMiddleware` that validates JWTs minted
by an upstream SaaS gateway (hotel admin login flow at
`avatars.hotel-tech.ai/admin/`). That path is designed for SaaS
administrators and requires the upstream gateway to be the identity
provider. Wellness mobile users are a different audience — they sign up
directly with the app, not through a hotel's admin portal.

## Decisions

1. **Laravel Sanctum personal access tokens for mobile auth.** Additive,
   not replacing. `saas.auth` middleware continues guarding the hotel
   SaaS admin routes; `auth:sanctum` guards the new mobile endpoints.
   Two audiences, two middleware, zero cross-impact.

2. **Mobile endpoints live under `/api/v1/` alongside everything else.**
   `POST /api/v1/auth/login`, `GET /api/v1/me`, `POST /api/v1/auth/logout`.
   No separate `/mobile/` prefix — the API is the API. Verticals and
   audiences are orthogonal to URL structure.

3. **Exception handler now defers to framework defaults for known
   exception types.** The previous blanket `Throwable → 500` handler
   swallowed `ValidationException` (422) and `AuthenticationException`
   (401) into 500s. Fixed to pass through framework exceptions with their
   native status codes; only uncategorised 5xx faults get the generic
   wrap.

4. **Expo SDK 54, React Native 0.81, TypeScript, no navigation library.**
   One screen, no routes — bringing `expo-router` or `react-navigation`
   now would be infrastructure without a purpose. Navigation enters in
   Phase 1 with the real app shell.

5. **`expo-secure-store` for token storage.** Uses Keychain on iOS and
   EncryptedSharedPreferences on Android. AsyncStorage would leak the
   token to anything with filesystem access on a rooted device.

6. **`fetch` over `axios`.** One dependency less, and the surface area
   we need (headers, JSON body, error body reading) is small. Axios
   earns its way in when interceptors or request cancellation become
   load-bearing — not yet.

7. **Single `EXPO_PUBLIC_API_URL` env var.** `EXPO_PUBLIC_*` is Expo's
   convention for build-time client-readable config. Keeping the surface
   tiny means the Phase 0 mobile app is a thin API client and nothing
   else.

8. **EAS project id in `app.json` under `extra.eas.projectId`.** Pre-
   existing EAS project (`7e22f92b-c4d5-47c8-94bc-1918a7a2f45d`) already
   bound to the GitHub repo. Committing the id — it is not a secret; it
   is a public identifier like a Sentry DSN.

9. **No Sentry, no analytics, no RevenueCat this phase.** All three are
   Phase 1+ work. Phase 0's exit criterion is one authenticated round-
   trip; adding more now would violate the "no chat, no onboarding"
   scope guard.

## Consequences

- Mobile teams can build against the backend the day this merges: the
  authenticated API surface is `POST /api/v1/auth/login`, `GET /api/v1/me`,
  `POST /api/v1/auth/logout`.
- Onboarding, registration, email verification, and password reset land
  in Phase 1. Phase 0 assumes a user exists (seeded locally, tinker-
  created in production).
- The exception handler change is a positive side-effect: future
  endpoints can throw `ValidationException` and get proper 422 responses
  without custom rendering.
- When the wellness mobile app adds Sentry (Phase 1), the mobile DSN
  joins `.env.example` and `app.json` plugins list.
- When a second vertical adds a mobile app, the entry point branches on
  a build-time vertical flag. The core client and Sanctum plumbing stay
  shared.

## Alternatives considered

- **Upstream SaaS gateway as mobile IdP.** Rejected — couples the
  wellness consumer app to a hotel-oriented admin gateway. Adds a
  deployment dependency for a product that is supposed to be
  configuration-driven.
- **JWT auth for mobile, matching `SaasAuthMiddleware`.** Rejected —
  the backend would have to mint tokens it doesn't mint today, and JWT
  rotation/revocation is harder than Sanctum's personal-access-token
  deletion.
- **Expo Router.** Rejected for Phase 0 — one screen, no routes. Re-
  evaluate when the Phase 1 app shell needs navigation.
- **Bare React Native.** Rejected — Expo Go is the published-mobile-
  shell path CLAUDE.md assumes ("builds on iOS and Android simulators"
  is achievable with zero platform config). Eject only if a native
  module needs it.

## Implementation notes

- `SchemaRollbackTest` was counting migrations manually (`--step=32`);
  the new `personal_access_tokens` table bumps the count to 33. If any
  future Phase 0 migration is added, update that step count or replace
  with `migrate:reset`.
- Laravel's test client caches the authenticated user on the auth
  manager within a single test — the logout test calls
  `$this->app['auth']->forgetGuards()` between the logout request and
  the follow-up `/me` request. Not needed in production (each request
  has its own process).
- The Phase 0 seeded user is `test@example.com` / `password` — for
  local dev only. Production users are created via tinker until the
  Phase 1 registration endpoint lands.
