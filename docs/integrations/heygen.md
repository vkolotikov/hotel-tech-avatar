# HeyGen Streaming Avatar

**Status:** `deprecated` — v1/v2 Streaming Avatar API returns `HTTP 410 Gone` as of 2026-04-17. Replaced by LiveAvatar (separate platform; see migration plan below).
**Last verified:** 2026-04-20
**Official docs:** https://docs.heygen.com (for historical reference only — endpoints listed are gone)

## What we use it for

Provides the real-time WebRTC talking-head avatar shown when voice mode is active in the hotel vertical chat (`/spa`). Purely cosmetic: the static PNG is shown the rest of the time to conserve credits, and TTS has a working fallback.

## Endpoints this project calls

| Method | Path | Purpose | Caller |
|---|---|---|---|
| POST | `/v1/streaming.create_token` | Mint a short-lived session token the browser hands to `@heygen/streaming-avatar` | `HeygenController::token()` ([app/Http/Controllers/Api/V1/HeygenController.php:18](app/Http/Controllers/Api/V1/HeygenController.php#L18)) |

Browser then speaks via `avatarStreamRef.current.speak(text, TaskType.REPEAT)` in [frontend/src/components/AvatarStream.tsx](frontend/src/components/AvatarStream.tsx); `AVATAR_STOP_TALKING` re-arms the mic (mirrors the OpenAI TTS `audio.onended` loop shape).

## Authentication

- `X-Api-Key: $HEYGEN_API_KEY` header on the token-mint request
- Secret: `HEYGEN_API_KEY` env var
- Config: `config/services.php` → `heygen.*`
- The API key is server-side only; the short-lived token (from the mint call) is what the browser sees.

## Known failure mode (why this is deprecated)

HeyGen's changelog originally promised v1/v2 Streaming Avatar would survive until 2026-10-31. They sunset it early — verified on 2026-04-17 that `POST /v1/streaming.create_token` returns **`HTTP 410 Gone`**. The controller returns a 502 to the frontend in that case, and `AvatarStream.tsx` shows an error overlay. The hotel SPA merge checklist treats this as pre-existing, not a regression.

## Migration path: LiveAvatar

- Separate platform at `app.liveavatar.com` — separate signup, separate API key, separate credit pool
- SDK: `@heygen/liveavatar-web-sdk`
- Endpoint: `POST /v2/embeddings` with `X-API-KEY` header — returns an embed URL or ready-to-use iframe script
- Existing HeyGen avatars are auto-copied to a LiveAvatar account on first login
- Pricing: $19/150cr Starter, $100/1000cr Essential (as of 2026-04-17 — verify before purchasing)

To revive voice-mode avatar streaming:
1. Sign up at `app.liveavatar.com`, purchase credits, grab `X-API-KEY` from the developers page → store as new env `LIVEAVATAR_API_KEY`
2. Replace `HeygenController::token()` to call `/v2/embeddings`
3. Swap the frontend SDK from `@heygen/streaming-avatar` to `@heygen/liveavatar-web-sdk`
4. Update this file's Status to `live` once shipped

Alternative if the extra subscription is undesirable: keep OpenAI TTS-only voice mode (already working) and remove the talking-head entirely, or evaluate D-ID (competitor, similar pricing).

## Cost and quota notes

Per-session cost is not currently metered — acceptable only while voice mode is gated behind the hotel vertical's existing usage controls. The wellness vertical must meter avatar-stream minutes through `llm_calls` / `token_usage_daily` at the point the vertical actually enables voice mode.

## Project-specific notes

- Default avatar is the stock `Anna_public_3_20240108` (configurable via `HEYGEN_DEFAULT_AVATAR`). To use a Photo Avatar created in the HeyGen dashboard, paste the `talking_photo_id` as the env default, or (later) add a per-agent `heygen_avatar_id` / `liveavatar_avatar_id` column.
- `AvatarStream.tsx` mounts only when `voiceMode` is true in `ChatPage.tsx` — this is load-bearing for credit cost.
- HTTPS / localhost required for mic permission on the walkthrough checklist.

## Change log

- 2026-04-17 — confirmed `/v1/streaming.create_token` returns `410 Gone`; Status moved to `deprecated`.
- 2026-04-20 — this file created from `_template.md` as part of Phase 0 integrations scaffold
