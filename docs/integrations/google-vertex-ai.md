# Google Vertex AI (Gemini)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://cloud.google.com/vertex-ai/generative-ai/docs

## What we use it for

Target **vision model for the wellness vertical** — skin analysis (Aura), food photo → nutrition (Nora), and form-check video frames (Axel). Gemini 3.1 Pro is the model named in `CLAUDE.md`; long-context use (uploaded lab PDFs) is the secondary use case. Promoted only if it beats the current baseline on the eval harness.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: `POST https://{region}-aiplatform.googleapis.com/v1/projects/{project}/locations/{region}/publishers/google/models/{model}:generateContent` via the `google-cloud-aiplatform` SDK or direct HTTP.

## Authentication

- Service-account JSON key (Application Default Credentials) — **not** a static API key
- Secret: `GOOGLE_APPLICATION_CREDENTIALS` points to a mounted key file in Laravel Cloud; local dev uses `.env` path
- Config will live under `services.google.*` with `project_id` and `region`
- **ZDR status:** Vertex AI data-governance settings (customer-managed encryption keys, no logging for model improvement) must be configured on the GCP project before user data flows. Record state in `docs/compliance/google-zdr.md` when onboarded.

## Error handling expectations

_To be filled in by the first implementer._ Anticipated: quota exhaustion (`RESOURCE_EXHAUSTED`), safety-filter blocks (empty candidate with `finishReason: SAFETY`), region failover.

## Cost and quota notes

- Pricing: https://cloud.google.com/vertex-ai/generative-ai/pricing
- Vision tokens ≠ text tokens — image tokenisation is model-specific and must be accounted separately in `llm_calls.metadata`.
- Daily vision-analysis cap per user is enforced at the app layer (spec §9.4).

## Project-specific notes

- **Must be introduced through the LLM client abstraction.**
- Vertex AI is preferred over direct Google AI Studio for production: it supports VPC-SC, CMEK, and regional data residency — all relevant to GDPR positioning.
- Streaming response format differs from OpenAI's — abstraction must normalise.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
