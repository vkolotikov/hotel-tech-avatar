# ElevenLabs (TTS)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://elevenlabs.io/docs

## What we use it for

Target streaming TTS upgrade from OpenAI's `gpt-4o-mini-tts` for the wellness vertical. Lower latency and a richer voice library matter for avatars with strong persona (Luna, Zen, Aura). Quality-measured upgrade — only adopted if it wins on the voice sub-track of the eval harness.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: `POST /v1/text-to-speech/{voice_id}/stream` with PCM/MP3 streaming.

## Authentication

- `xi-api-key: $ELEVENLABS_API_KEY` header
- Secret: `ELEVENLABS_API_KEY` env var; config under `services.elevenlabs.*`

## Error handling expectations

_To be filled in by the first implementer._ Anticipated: character-quota exhaustion, voice cloning moderation holds.

## Cost and quota notes

- Pricing: https://elevenlabs.io/pricing — per-character
- Per-tier voice minute caps must land in `llm_calls` (with `purpose=tts`).

## Project-specific notes

- Streaming is required: buffered TTS destroys the conversational feel the wellness avatars need.
- Voice IDs belong on `agents.persona_json` (not hard-coded).
- Do not introduce before the eval harness has a voice-quality sub-track.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
