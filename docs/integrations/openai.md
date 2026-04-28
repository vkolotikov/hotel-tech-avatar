# OpenAI

**Status:** `live`
**Last verified:** 2026-04-28
**Official docs:** https://platform.openai.com/docs
**gpt-5.5 / Responses migration notes:** [openai-gpt-5.5-notes.md](openai-gpt-5.5-notes.md)

## What we use it for

Primary generation, STT, and TTS for the hotel vertical today. Planned to remain the wellness v1.0 brain at launch per `docs/PROJECT_SPEC.md`; upgrade path to Anthropic/Google/ElevenLabs/Deepgram is a quality-measured decision against the eval harness, not a deadline.

Chat goes through `App\Services\Llm\LlmClient`. The client picks one of two providers based on `LLM_API_BACKEND` (default `responses`):

- **`OpenAiResponsesProvider`** (default) — calls `POST /v1/responses`. Required for gpt-5.5+ to unlock `reasoning.effort`, `text.verbosity`, strict Structured Outputs via `text.format`, and the smaller per-turn payload that prompt caching keys off `instructions`.
- **`OpenAiProvider`** (legacy) — calls `POST /v1/chat/completions`. Kept for the gpt-4o family and as a one-flag rollback if Responses regresses anywhere. Set `LLM_API_BACKEND=chat` in env to force this path.

STT, TTS, files, and vector-store calls stay in `app/Services/OpenAiService.php`. New generation code must use `LlmClient`; `OpenAiService::chat()` has been removed to keep one cut-over point.

## Endpoints this project calls

| Method | Path | Purpose | Caller |
|---|---|---|---|
| POST | `/v1/responses` | Agent text replies (gpt-5.5+, default) | `LlmClient::chat()` via `OpenAiResponsesProvider::chat()` ([app/Services/Llm/Providers/OpenAiResponsesProvider.php](app/Services/Llm/Providers/OpenAiResponsesProvider.php)) |
| POST | `/v1/chat/completions` | Agent text replies (legacy fallback) | `LlmClient::chat()` via `OpenAiProvider::chat()` ([app/Services/Llm/Providers/OpenAiProvider.php](app/Services/Llm/Providers/OpenAiProvider.php)) |
| POST | `/v1/audio/transcriptions` | Whisper / gpt-4o-transcribe STT for voice mode | `OpenAiService::transcribe()` ([app/Services/OpenAiService.php:63](app/Services/OpenAiService.php#L63)) |
| POST | `/v1/audio/speech` | `gpt-4o-mini-tts` TTS fallback when HeyGen unavailable | `OpenAiService::speak()` ([app/Services/OpenAiService.php:85](app/Services/OpenAiService.php#L85)) |
| POST | `/v1/files` | Upload docs for vector store | `OpenAiService::uploadFile()` ([app/Services/OpenAiService.php:108](app/Services/OpenAiService.php#L108)) |
| POST | `/v1/vector_stores` | Create vector store | `OpenAiService::createVectorStore()` ([app/Services/OpenAiService.php:127](app/Services/OpenAiService.php#L127)) |
| POST | `/v1/vector_stores/{id}/files` | Attach file to store | `OpenAiService::addFileToVectorStore()` ([app/Services/OpenAiService.php:135](app/Services/OpenAiService.php#L135)) |
| DELETE | `/v1/files/{id}` | Cleanup | `OpenAiService::deleteFile()` ([app/Services/OpenAiService.php:145](app/Services/OpenAiService.php#L145)) |

Model defaults (overridable per-call and per-env):
- Chat: `gpt-5.5` (`OPENAI_MODEL_DEFAULT`) — routed through `OpenAiResponsesProvider` by default. The provider gates `reasoning.effort` on the model id (gpt-5/o-series only) so flipping back to `gpt-4o` is safe without code changes.
- STT: `gpt-4o-transcribe` (`OPENAI_TRANSCRIBE_MODEL`)
- TTS: `gpt-4o-mini-tts` (`OPENAI_TTS_MODEL`)
- Embeddings (wellness vertical, Phase 1): `text-embedding-3-large` — 3072 dims, matches the `vector(3072)` column in `knowledge_chunks`.

Responses-API tuning knobs (gpt-5/o-series only — silently ignored on gpt-4o):
- `OPENAI_REASONING_EFFORT` (`low`/`medium`/`high`/`xhigh`, default `low`). Per-agent override: `agents.reasoning_effort`.
- `OPENAI_VERBOSITY` (`low`/`medium`/`high`, default `low`). Per-agent override: `agents.verbosity`.

Both per-agent fields are NULL by default — when NULL the env value applies. Admin can set them per avatar via the agent edit form.

## Authentication

- `Authorization: Bearer $OPENAI_API_KEY` on every request
- Secret: `OPENAI_API_KEY` env var; in production via Laravel Cloud secret store
- Config keys: `config/services.php` → `openai.*`
- **ZDR status:** dashboard sharing disabled org-wide; formal contractual ZDR requested 2026-04-19 and **gates Phase 3 user data**. No real user health content may hit OpenAI until ZDR is signed. State tracked in `docs/compliance/openai-zdr.md`.

## Error handling expectations

- Service uses `Illuminate\Support\Facades\Http` with a 45s default timeout (`OPENAI_TIMEOUT_SECONDS`).
- Both providers throw `RuntimeException` on non-2xx. `OpenAiResponsesProvider` includes a 500-char snippet of the response body in the exception (Responses errors are JSON metadata about the request — model name, parameter validation — not user content), which makes diagnosis far easier than the legacy provider's status-only message. Callers classify by status.
- Rate limits: 429s are currently unhandled (no backoff). Transient vs rate-limit vs content-policy classification + exponential backoff with jitter is a Phase 1 addition.
- `/v1/responses` returns the assembled text under `output_text` (helper) or `output[].content[].text` for `output_text` blocks; we read `usage.input_tokens` / `usage.output_tokens` / `usage.total_tokens` and map them to our `prompt_tokens` / `completion_tokens` / `total_tokens` ledger names so cost accounting stays uniform across providers.
- `/v1/chat/completions` returns `choices[0].message.content` and `usage.prompt_tokens` / `completion_tokens` / `total_tokens` directly. Missing `usage` is possible on stream-cancellation — defaulted to 0.

## Structured Outputs (replaces `response_format: json_object`)

The wellness chat path constrains the model to `{ reply: string, suggestions: string[] }` via a strict `json_schema` defined in `GenerationService::wellnessReplyJsonSchema()`. On the Responses path the provider translates this to `text.format = { type: 'json_schema', name, strict: true, schema }`; on the Chat Completions path it passes through unchanged. Strict mode requires every property be listed in `required` and `additionalProperties: false`.

The system prompt no longer describes the JSON shape — Structured Outputs is the contract, and the gpt-5.5 prompting guide explicitly recommends letting the schema do that work rather than restating it in prose.

## Cost and quota notes

- Pricing: https://openai.com/api/pricing
- Each call's `prompt_tokens` / `completion_tokens` / `total_tokens` + measured latency are written to the `llm_calls` ledger by `LlmClient` on every success and every failure (error rows carry `metadata.error_class`, never the raw message).
- Per-session cap enforced at the app level via `conversations.session_cost_usd_cents` (spec §9.4).
- Daily per-user cap enforced via `token_usage_daily` (spec §9.4).

## Project-specific notes

- `max_tokens` is set to 1500 (`OPENAI_MAX_OUTPUT_TOKENS`). Raised from 220 on 2026-04-27 because structured asks ("give me a meal plan", "compare these in a table") were truncating mid-answer. Conversational replies still cost 60-150 tokens — the cap only matters when the user requests substance. Per-agent override remains a future option but isn't yet wired.
- Context window capped at 20 recent messages (`OPENAI_MAX_CONTEXT_MESSAGES`) plus retrieved knowledge trimmed to 12k chars (`OPENAI_MAX_KNOWLEDGE_CHARS`). These heuristics predate retrieval-based grounding and should be revisited alongside the wellness RAG pipeline.
- `uploadFile` + vector-store endpoints are for OpenAI's hosted assistants feature. The wellness vertical will use local pgvector (`knowledge_chunks.embedding`) and skip these — keep the OpenAI vector-store code path, but don't build new features on it.
- `base_url` is env-overridable (`OPENAI_API_BASE_URL`) for staging / proxy routing; defaults to `https://api.openai.com/v1`.

## Change log

- 2026-04-19 — ZDR request sent to OpenAI support; documented in `docs/compliance/openai-zdr.md`
- 2026-04-20 — this file created from `_template.md` as part of Phase 0 integrations scaffold
- 2026-04-20 — chat completions moved behind `LlmClient` via `OpenAiProvider`.
  `store=false` is sent unconditionally on every chat request (belt-and-braces
  with the dashboard-level opt-out). `OpenAiService::chat()` removed; STT / TTS
  / file / vector-store helpers stay in `OpenAiService`.
- 2026-04-26 — `gpt-5.5` added to the admin model picker as the new flagship.
  Existing avatars stay on whatever model they were configured with; no
  automatic migration. Reference: https://developers.openai.com/api/docs/guides/latest-model
- 2026-04-26 — admin endpoint `POST /api/v1/admin/voices/preview` added —
  generates short TTS samples per voice for previewing in the admin form.
  Audio is cached on disk by `sha1(voice|text|model)` to avoid re-billing on
  repeat clicks. Returns a base64 `data:audio/mpeg` URL.
- 2026-04-28 — Responses API migration. `OpenAiResponsesProvider` added; the
  `LlmServiceProvider` now dispatches between Responses and Chat Completions
  via `LLM_API_BACKEND` (default `responses`). `LlmRequest` gained
  `reasoningEffort` and `verbosity`. `GenerationService` switched from the
  legacy `response_format: json_object` to a strict `json_schema` (see
  `wellnessReplyJsonSchema()`), and `SystemPromptBuilder` was rewritten
  per the gpt-5.5 prompting guide — outcome-first phrasing, dropped the
  JSON contract from the prompt body, softened load-bearing
  `ALWAYS`/`NEVER` away from non-safety guidance. Default model bumped
  to `gpt-5.5`; existing wellness avatars promoted via
  `2026_04_28_000002_promote_wellness_avatars_to_gpt55`. Per-agent
  `reasoning_effort` / `verbosity` columns added in `2026_04_28_000001`
  with admin UI dropdowns.
