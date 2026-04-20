# {Service name}

**Status:** `planned` | `live` | `deprecated`
**Last verified:** YYYY-MM-DD
**Official docs:** https://... (must be an official URL, not a tutorial site)

## What we use it for

One paragraph. Which vertical(s), which feature area.

## Endpoints this project calls

| Method | Path | Purpose | Caller (file:line) |
|---|---|---|---|
| GET | `/v1/...` | ... | `app/Services/...` |

Only list endpoints that actually exist in our code. Remove the rest.

## Authentication

- Header / query param shape
- Token lifetime and refresh approach
- Where the secret lives (env var name, Laravel Cloud secret store)
- ZDR / DPA / compliance status if applicable

## Error handling expectations

- Rate-limit behaviour (headers, backoff)
- Common failure modes we have observed (e.g. `410 Gone`, `429`, partial responses)
- Our retry policy

## Cost and quota notes

- Pricing link
- Which of our tiers (free / basic / pro / ultimate) can use this
- Daily / monthly caps we enforce on our side

## Project-specific notes

Anything non-obvious that would cost the next engineer an hour to rediscover: version pins, undocumented fields, regional endpoints, account-specific config.

## Change log

- YYYY-MM-DD — what changed and who noticed (commit ref or PR)
