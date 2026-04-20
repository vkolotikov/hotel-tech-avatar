# PostHog

**Status:** `planned` — **Phase 3+** only (per `CLAUDE.md` §Phase 0: "PostHog is not wired yet — that waits for Phase 3 when there is a product to analyse.")
**Last verified:** 2026-04-20
**Official docs:** https://posthog.com/docs

## What we use it for

Product analytics: funnels (sign-up → first message → paywall → subscription), retention, feature flags. Distinct from Langfuse (LLM quality) and Sentry (errors) — PostHog answers "what are users doing" not "what broke" or "was the answer good".

## Endpoints this project calls

_None yet — do not wire before Phase 3._ Anticipated:

- **Mobile SDK**: `posthog-react-native` — event capture, feature flags
- **Backend**: `posthog-php` for server-side events (webhook-triggered conversions, etc.)
- EU cloud host by default for GDPR.

## Authentication

- `POSTHOG_PROJECT_API_KEY` (client, safe to ship)
- `POSTHOG_PERSONAL_API_KEY` (server, secret) for feature-flag evaluation and management

## Error handling expectations

- SDK is fire-and-forget for analytics.
- Feature-flag evaluation must have a sane default on SDK failure — never gate critical paths on PostHog being reachable.

## Cost and quota notes

- Pricing: https://posthog.com/pricing — event volume + recording minutes.
- Session recordings are off by default; evaluate PHI risk per surface before enabling.

## Project-specific notes

- **Event schema versioning**: pick a naming convention and an ownership doc before the first event ships. Dirty event data is permanent.
- Feature flags should be used sparingly for user-facing experiments — they are not a substitute for proper A/B infrastructure for prompts (that belongs in `agent_prompt_versions`).
- PII policy: `distinct_id` is an internal UUID, not email. No free-text user content in event properties.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
