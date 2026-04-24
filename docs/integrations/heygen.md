# HeyGen Streaming Avatar → LiveAvatar

**Status:** `deprecated` (original HeyGen endpoint) / `backend scaffolded` (LiveAvatar successor — awaiting operator signup + per-agent ID mapping before it can serve traffic).
**Last verified:** 2026-04-24
**Official docs:**
  - Historical HeyGen: https://docs.heygen.com (endpoints listed are gone)
  - Current LiveAvatar: https://app.liveavatar.com (sign-in required for developer portal)

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

## Operator checklist — to bring voice-mode avatar online

Server code is in place (stub controller + schema + config). To light it up end-to-end:

1. **Sign up at `https://app.liveavatar.com`** — separate account from the main HeyGen dashboard. Existing HeyGen avatars auto-copy on first sign-in.
2. **Purchase credits** — Starter $19/150 credits, Essential $100/1000 credits (verify current pricing at signup).
3. **Developers page → API Key** — generate a key, copy it.
4. **Laravel Cloud env:** set `LIVEAVATAR_API_KEY=<key>`. Redeploy.
5. **Map per-agent avatars** — for each wellness agent (nora, integra, luna, zen, axel, aura), pick a LiveAvatar avatar in the dashboard and paste its `avatar_id` (and optional `voice_id`) into the agent row:
   ```sql
   UPDATE agents SET liveavatar_avatar_id = '<hg_id>' WHERE slug = 'nora';
   -- etc
   ```
   (Or expose this via the Filament admin UI once built.)
6. **Confirm the upstream endpoint** — the exact POST path + request/response shape isn't in the public developer docs and will need to be read off the LiveAvatar developer portal once you're signed in. Likely candidates: `POST /v2/embeddings` (named in the original migration note) or a newer `/v3/sessions`. Drop the confirmed endpoint + payload into `app/Http/Controllers/Api/V1/LiveAvatarController.php::createSession()` and remove the `501 liveavatar_endpoint_pending` short-circuit.
7. **Mobile WebView integration** — once step 6 returns a real embed URL, swap the mobile `LiveAvatarView` from placeholder to `react-native-webview` loading the embed. WebRTC + mic permission passthrough already supported on modern iOS / Android WebView; need `expo-permissions` microphone entry in `app.json`.

When all of the above lands, status flips from `backend scaffolded` to `live`.

## Change log

- 2026-04-24 — LiveAvatar migration scaffolded server-side: `liveavatar_avatar_id` + `liveavatar_voice_id` columns on `agents`; `services.liveavatar.*` config + `LIVEAVATAR_API_KEY` env; new `LiveAvatarController` with `POST /api/v1/liveavatar/session` returning 503 while key empty and 422 while the agent isn't mapped. Upstream endpoint intentionally left as a 501 stub — to be filled in when an operator signs up and reads the actual payload shape off the developer portal. Legacy `HeygenController::token` left in place so the hotel SPA's existing 502 behaviour is unchanged.
- 2026-04-17 — confirmed `/v1/streaming.create_token` returns `410 Gone`; Status moved to `deprecated`.
- 2026-04-20 — this file created from `_template.md` as part of Phase 0 integrations scaffold
