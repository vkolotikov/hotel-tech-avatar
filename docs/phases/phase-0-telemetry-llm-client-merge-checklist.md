# Phase 0 — Telemetry + LlmClient merge checklist

## Automated

- [x] `php artisan test` — 72 passed (258 assertions). Includes
  `LlmRequestTest`, `OpenAiProviderTest`, `NullTracerTest`, `LangfuseTracerTest`,
  `LlmCallTest`, `LlmClientTest`.
- [x] `php artisan test --filter=HotelSpaRegressionTest` — 3 passed (24
  assertions). Hotel unchanged.
- [x] `php artisan test --filter=LlmClientTest` — ledger happy path + error
  ledger path pass. Error path asserts `metadata.error_class` is set and the
  raw exception message is absent.
- [x] `grep -r "OpenAiService::class" app/` — only STT/TTS call sites remain
  (`ConversationController::transcribe` and `ConversationController::speak`).
  No `$openai->chat(` anywhere under `app/`.

## Manual

- [ ] Hotel SPA chat round-trip succeeds against local
  `http://avatar.local/spa`.
- [ ] One real Langfuse trace visible in the Langfuse UI (Task C.9).
- [ ] One Sentry event visible in the Sentry UI from a thrown exception
  (Task C.10).
- [ ] `config/sentry.php` before-send scrubber strips `message` / `prompt` /
  `response` keys.
- [ ] `.env.example` lists `SENTRY_LARAVEL_DSN`, `LANGFUSE_*` variables,
  `LLM_DEFAULT_PROVIDER`, `LLM_LEDGER_ENABLED`.

## DB smoke-test

```sql
SELECT provider, model, prompt_tokens, completion_tokens, latency_ms, trace_id
FROM llm_calls ORDER BY id DESC LIMIT 5;
-- expect: rows with provider=openai, non-null tokens/latency/trace_id
```

## Not in this phase (verify no one added them)

- [x] No `AnthropicProvider` or `GoogleProvider` class.
- [x] No streaming support in `LlmClient`.
- [x] No retry/backoff in `OpenAiProvider`.
- [x] No mobile Sentry code (waits for Expo skeleton plan).
- [x] No PostHog wiring (waits for Phase 3).
