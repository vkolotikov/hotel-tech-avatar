# ADR — Phase 0 Telemetry + LlmClient

**Date:** 2026-04-20
**Status:** Accepted
**Authors:** platform

## Context

CLAUDE.md §Phase 0 names Sentry (backend + mobile) and Langfuse on one real
LLM call as exit criteria. It also requires a single LLM client abstraction
that unifies tracing, cost accounting, and ZDR enforcement. This ADR records
the Phase 0 decisions; multi-provider and verification-pipeline work is
Phase 1.

## Decisions

1. **Thin provider-agnostic client.** `LlmClient::chat(LlmRequest): LlmResponse`.
   `ProviderInterface` with only `chat()`. STT, TTS, file and vector-store
   calls stay in `OpenAiService` — they are not the observability gate.

2. **store=false unconditional on OpenAI.** Enforced in `OpenAiProvider`, not
   at the caller. Belt-and-braces with the dashboard-level opt-out documented
   in `docs/compliance/openai-zdr.md`. Formal ZDR remains pending; this rule
   does not change when ZDR lands.

3. **Langfuse via plain HTTP, no SDK.** The community `langfuse-php` package
   is sparsely maintained and the ingestion API (`POST /api/public/ingestion`
   with a `batch` of events) is small. A direct client keeps the dependency
   graph minimal and the tracing call-site under our control.

4. **Tracer is fire-and-forget.** Ingestion failures are swallowed with a log
   warning. Tracing never breaks the generation path (matches
   `docs/integrations/langfuse.md`).

5. **Ledger every call through LlmClient.** `llm_calls` row written on every
   invocation — success and failure. Error rows carry `metadata.error_class`
   (FQCN) — never the raw exception message, which may echo user content from
   future providers. Bookkeeping failures during the error path are caught and
   logged, so a DB/Langfuse outage cannot mask the original provider exception.
   This is the cost/latency ground truth; Langfuse traces are the debugging
   lens.

6. **Sentry backend only in this phase.** `sentry/sentry-laravel` installed,
   DSN env-configured, before-send scrubber strips message/prompt/response
   keys from request data. Mobile Sentry deferred to the Expo skeleton plan
   because `mobile/` does not yet exist.

7. **Hotel chat migrates now, not later.** Phase 0 value is measured by the
   hotel regression staying green while going through the abstraction.
   Delaying the migration would leave two generation paths indefinitely.

## Consequences

- Adding Anthropic is a new `AnthropicProvider` + binding flip — no call-site
  changes, no tracer changes.
- Cost-aware orchestration (Phase 1) queries `llm_calls` for per-conversation
  totals without touching provider code.
- The verification pipeline (Phase 1) spawns child calls by passing
  `parentLlmCallId` into `LlmRequest`.
- Retry/backoff is still caller-side. Transient/rate-limit/content-policy
  classification is a Phase 1 addition to `OpenAiProvider`, mirrored on
  future providers.

## Alternatives considered

- **Streaming-first client.** Rejected — no streaming consumer today; SSE
  around a blocking call works for hotel. Streaming is a Phase 1+ addition
  when the wellness verification pipeline needs token-by-token output.
- **Use `openai-php/client` SDK inside LlmClient.** Rejected — three
  dependencies for three providers eventually; plain `Http::` keeps the
  abstraction uniform.
- **Wrap Langfuse via Sentry performance monitoring.** Rejected — Sentry is
  error-first; LLM observability warrants a purpose-built tool.

## Implementation notes

- `sentry:publish --dsn=...` writes `config/sentry.php` but also appends to
  `.env`. Revert `.env` changes; only `.env.example` should be committed.
- `Http::withBasicAuth($pub, $sec)` is the Langfuse auth pattern — the public
  key is the username, the secret is the password.
- `OpenAiService::chat()` deletion was only safe because a single grep
  confirmed one call site (`ConversationController` line ~231). Any new call
  that needs generation must use `LlmClient`.
