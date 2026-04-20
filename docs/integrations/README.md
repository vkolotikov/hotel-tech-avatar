# Third-party integration docs

One file per third-party service. This directory is the team's institutional memory for every external API this project talks to.

## Convention (per `CLAUDE.md` §"API integration rules")

Every integration file contains:

1. **Official documentation URL** — the authoritative one, not a blog post
2. **Endpoints used** — just the ones this project actually calls
3. **Authentication pattern** — header shape, token lifetime, where the secret lives
4. **Project-specific notes** — quirks, rate-limit behaviour, response-shape surprises, version pins
5. **Status** — one of:
   - `live` — in production code right now
   - `planned` — named in the spec, code not yet written
   - `deprecated` — code exists but the API is sunset or the integration is being replaced

## Before writing code that touches a third-party API

1. Read the matching file in this directory.
2. **Fetch the live URL** listed in the file — your training data is stale for most APIs.
3. If no file exists for the service, create one from `_template.md` before writing code.
4. Summarise to the human: endpoints, auth, error handling, open questions. Wait for approval.
5. After implementation works, update the file with anything discovered — auth quirks, rate-limit behaviour, response-shape surprises, version notes.

## Secret handling

No API keys live in these files. Keys go to Laravel Cloud's secret store in production and `.env` locally, with `.env.example` committed for onboarding. Never read secrets from user input and never write them into docs.
