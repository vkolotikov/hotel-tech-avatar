# Deepgram (STT)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://developers.deepgram.com/docs

## What we use it for

Target streaming STT upgrade from OpenAI's `gpt-4o-transcribe` for the wellness vertical. **Nova-3-Medical** is the specific model named in `CLAUDE.md` — its medical-terminology vocabulary is the reason for the swap. Quality-measured: only promoted if it beats baseline word-error-rate on health utterances in the eval harness.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: WebSocket `/v1/listen?model=nova-3-medical&...` for live streaming; REST `/v1/listen` for file-based transcription.

## Authentication

- `Authorization: Token $DEEPGRAM_API_KEY` header
- Secret: `DEEPGRAM_API_KEY` env var; config under `services.deepgram.*`
- **ZDR equivalent:** Deepgram offers a "no data retention" project setting — must be enabled before routing user health audio.

## Error handling expectations

_To be filled in by the first implementer._ Anticipated: WebSocket reconnection strategy, interim-result vs final-result handling, model-unavailable fallback to a generic model.

## Cost and quota notes

- Pricing: https://deepgram.com/pricing — per-minute
- Nova-3-Medical is more expensive than the generic Nova model — gated to Pro/Ultimate tiers per spec §subscription.

## Project-specific notes

- The mobile app streams audio client-side directly; Laravel issues short-lived tokens but never proxies audio (per `CLAUDE.md` §"Real-time media runs client-side").
- Punctuation + smart-format flags are on by default for clinical transcription.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
