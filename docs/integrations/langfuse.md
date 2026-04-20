# Langfuse

**Status:** `planned` (wiring is a Phase 0 DoD item — see `docs/phases/phase-0-telemetry.md`)
**Last verified:** 2026-04-20
**Official docs:** https://langfuse.com/docs

## What we use it for

LLM observability: end-to-end traces of every generation, including the verification pipeline's sub-calls (claim extraction → grounding → critic → citation → safety). Phase 0 DoD: "Langfuse is receiving a trace on at least one real LLM call routed through the LLM client abstraction."

## Endpoints this project calls

Langfuse SDK handles transport — envelope uploads go to the Langfuse host (cloud or self-hosted). We call the SDK, not the API.

- **PHP**: either the community `langfuse-php` package or direct HTTP from the LLM client abstraction
- **Python microservice**: `langfuse` pip package
- **Mobile**: not expected — mobile does not make LLM calls directly

## Authentication

- `LANGFUSE_PUBLIC_KEY` + `LANGFUSE_SECRET_KEY` + `LANGFUSE_HOST`
- Cloud EU host by default for GDPR positioning; self-host option TBD if ZDR-equivalent contractual terms aren't available on the cloud plan.

## Error handling expectations

- SDK is non-blocking: tracing failures never break the generation path.
- Dropped traces are acceptable; missing-trace alerts are a Langfuse-side concern.

## Cost and quota notes

- Pricing: https://langfuse.com/pricing — observations/month based.
- Each LLM call = one trace. Our verification pipeline produces many nested observations per trace — budget accordingly.

## Project-specific notes

- **The LLM client abstraction is the integration point.** Do not sprinkle `langfuse()->trace(...)` across the codebase — it belongs in exactly one place so every provider gets traced uniformly.
- `trace_id` written to `llm_calls.trace_id` links app-layer ledger rows to Langfuse traces for debugging.
- **PHI scrubbing**: the same concerns as Sentry apply. Evaluate Langfuse's data-masking features vs. handling scrubbing ourselves before routing production wellness traces.
- PostHog analytics and Langfuse tracing are complementary, not overlapping — PostHog is product funnel, Langfuse is LLM quality.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
