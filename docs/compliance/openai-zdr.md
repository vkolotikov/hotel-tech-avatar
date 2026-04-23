# OpenAI — data retention posture

**Last updated:** 2026-04-19
**Owner:** platform / compliance

## Current status

### Dashboard-level data sharing (organisation)

All three organisation-level sharing toggles in the OpenAI console are **Disabled**:

- "Share inputs and outputs with OpenAI" — Disabled
- "Share evaluation and fine-tuning data with OpenAI" — Disabled
- "Enable sharing of model feedback from the Platform" — Disabled

No prompts, responses, evaluation data, or feedback are shared with OpenAI for training or model-improvement purposes.

### API call logging (per-request)

Organisation-level default permits logging. Per-request `store=false` is the enforcement mechanism.

- **Wellness vertical:** all calls MUST pass `store=false`. Enforced centrally by the LLM client abstraction (`app/Services/LlmClient.php`, forthcoming in the Phase 0 telemetry plan).
- **Hotel vertical:** logging remains on by default (helpful for debugging existing flows). Any specific call may opt out via `store=false`.

### Formal contractual Zero Data Retention (ZDR)

**Not yet in place as of 2026-04-19.** Formal request sent to OpenAI the same day.

Contractual ZDR is a stronger commitment than the dashboard toggles — it eliminates the 30-day abuse-monitoring retention. Expected processing time: a few days to a few weeks.

## Gate

Per spec hard-rule #5 (CLAUDE.md, `docs/PROJECT_SPEC.md`): **real wellness-user health data must not be routed to OpenAI in production until formal ZDR is in place.** Phase 0 ships no user data, so this is not yet blocking. The gate applies from Phase 3 onwards.

## Changelog

- 2026-04-23 — still awaiting formal ZDR reply from OpenAI. Gate still non-blocking (Phase 0; no wellness-user data in production yet). Owner to nudge if no reply by 2026-05-03.
- 2026-04-19 — recorded initial state; formal ZDR request filed.
