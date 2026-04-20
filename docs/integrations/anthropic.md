# Anthropic (Claude)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://docs.anthropic.com

## What we use it for

Target **primary generation model for the wellness vertical** once the eval harness can demonstrate a measurable quality lift over the OpenAI baseline. Claude Opus 4.7 is the primary target; Claude Haiku 4.5 is the target for lightweight tasks (claim extraction, safety classification) where latency and cost matter more than peak quality. Per `CLAUDE.md`, promotion is measurement-driven, never a switch flip.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: `POST /v1/messages` for generation and tool use. Prompt caching (`cache_control` blocks) and streaming are both required — not optional — for the wellness flow.

## Authentication

- Expected: `x-api-key: $ANTHROPIC_API_KEY` + `anthropic-version` header
- Secret will live as `ANTHROPIC_API_KEY` env var; config under `services.anthropic.*`
- **ZDR status:** ZDR must be confirmed with Anthropic before routing any real user health content. Record state in `docs/compliance/anthropic-zdr.md` when the provider is actually onboarded.

## Error handling expectations

_To be filled in by the first implementer._ Anticipated: rate-limit (429) with `retry-after`, overloaded (529), context-window (400) — all need distinct handling in the LLM client abstraction.

## Cost and quota notes

- Pricing: https://www.anthropic.com/pricing
- Input/output token costs differ dramatically between Opus and Haiku — cost modelling belongs in `llm_calls` per-call.
- Prompt caching is mandatory: without it, re-sending the same system prompt + retrieved chunks on every turn blows the cost envelope.

## Project-specific notes

- **Must be introduced through the LLM client abstraction**, not as a second direct-HTTP service alongside `OpenAiService`. The abstraction's cut-over is the point of this integration existing.
- Model IDs as of Jan 2026 cutoff: `claude-opus-4-7`, `claude-sonnet-4-6`, `claude-haiku-4-5-20251001`. Verify against live docs before wiring.
- Do not introduce Anthropic before the eval harness exists — we need a baseline to measure against.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
