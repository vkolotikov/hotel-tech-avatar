# OpenAI

**Status:** `live`
**Last verified:** 2026-04-20
**Official docs:** https://platform.openai.com/docs

## What we use it for

Primary generation, STT, and TTS for the hotel vertical today. Planned to remain the wellness v1.0 brain at launch per `docs/PROJECT_SPEC.md`; upgrade path to Anthropic/Google/ElevenLabs/Deepgram is a quality-measured decision against the eval harness, not a deadline.

All calls go through `app/Services/OpenAiService.php`. New code should not talk to OpenAI directly — route through the service so the future LLM-client abstraction has a single cut-over point.

## Endpoints this project calls

| Method | Path | Purpose | Caller |
|---|---|---|---|
| POST | `/v1/chat/completions` | Agent text replies | `OpenAiService::chat()` ([app/Services/OpenAiService.php:24](app/Services/OpenAiService.php#L24)) |
| POST | `/v1/audio/transcriptions` | Whisper / gpt-4o-transcribe STT for voice mode | `OpenAiService::transcribe()` ([app/Services/OpenAiService.php:63](app/Services/OpenAiService.php#L63)) |
| POST | `/v1/audio/speech` | `gpt-4o-mini-tts` TTS fallback when HeyGen unavailable | `OpenAiService::speak()` ([app/Services/OpenAiService.php:85](app/Services/OpenAiService.php#L85)) |
| POST | `/v1/files` | Upload docs for vector store | `OpenAiService::uploadFile()` ([app/Services/OpenAiService.php:108](app/Services/OpenAiService.php#L108)) |
| POST | `/v1/vector_stores` | Create vector store | `OpenAiService::createVectorStore()` ([app/Services/OpenAiService.php:127](app/Services/OpenAiService.php#L127)) |
| POST | `/v1/vector_stores/{id}/files` | Attach file to store | `OpenAiService::addFileToVectorStore()` ([app/Services/OpenAiService.php:135](app/Services/OpenAiService.php#L135)) |
| DELETE | `/v1/files/{id}` | Cleanup | `OpenAiService::deleteFile()` ([app/Services/OpenAiService.php:145](app/Services/OpenAiService.php#L145)) |

Model defaults (overridable per-call and per-env):
- Chat: `gpt-4o` (`OPENAI_MODEL_DEFAULT`)
- STT: `gpt-4o-transcribe` (`OPENAI_TRANSCRIBE_MODEL`)
- TTS: `gpt-4o-mini-tts` (`OPENAI_TTS_MODEL`)
- Embeddings (wellness vertical, Phase 1): `text-embedding-3-large` — 3072 dims, matches the `vector(3072)` column in `knowledge_chunks`.

## Authentication

- `Authorization: Bearer $OPENAI_API_KEY` on every request
- Secret: `OPENAI_API_KEY` env var; in production via Laravel Cloud secret store
- Config keys: `config/services.php` → `openai.*`
- **ZDR status:** dashboard sharing disabled org-wide; formal contractual ZDR requested 2026-04-19 and **gates Phase 3 user data**. No real user health content may hit OpenAI until ZDR is signed. State tracked in `docs/compliance/openai-zdr.md`.

## Error handling expectations

- Service uses `Illuminate\Support\Facades\Http` with a 45s default timeout (`OPENAI_TIMEOUT_SECONDS`).
- Failed responses throw `RuntimeException` with the response body — caller is responsible for classifying transient vs. hard failures.
- Rate limits: 429s are currently unhandled (no backoff). When we build the LLM client abstraction, add: transient vs rate-limit vs content-policy classification + exponential backoff with jitter.
- `/v1/chat/completions` returns `choices[0].message.content`; we read `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens` for accounting. Missing `usage` is possible on stream-cancellation — defaulted to 0.

## Cost and quota notes

- Pricing: https://openai.com/api/pricing
- Each call's `prompt_tokens` / `completion_tokens` / `total_tokens` + measured latency are returned from `chat()` and belong in the `llm_calls` ledger (Phase 1).
- Per-session cap enforced at the app level via `conversations.session_cost_usd_cents` (spec §9.4).
- Daily per-user cap enforced via `token_usage_daily` (spec §9.4).

## Project-specific notes

- `max_tokens` is currently capped at 220 (`OPENAI_MAX_OUTPUT_TOKENS`) — short-reply tuning for the hotel concierge flow. Wellness vertical will need this raised and made per-agent.
- Context window capped at 20 recent messages (`OPENAI_MAX_CONTEXT_MESSAGES`) plus retrieved knowledge trimmed to 12k chars (`OPENAI_MAX_KNOWLEDGE_CHARS`). These heuristics predate retrieval-based grounding and should be revisited alongside the wellness RAG pipeline.
- `uploadFile` + vector-store endpoints are for OpenAI's hosted assistants feature. The wellness vertical will use local pgvector (`knowledge_chunks.embedding`) and skip these — keep the OpenAI vector-store code path, but don't build new features on it.
- `base_url` is env-overridable (`OPENAI_API_BASE_URL`) for staging / proxy routing; defaults to `https://api.openai.com/v1`.

## Change log

- 2026-04-19 — ZDR request sent to OpenAI support; documented in `docs/compliance/openai-zdr.md`
- 2026-04-20 — this file created from `_template.md` as part of Phase 0 integrations scaffold
