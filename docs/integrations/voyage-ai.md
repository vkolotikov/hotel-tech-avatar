# Voyage AI (Biomedical embeddings)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://docs.voyageai.com

## What we use it for

Biomedical-domain embedding model for the wellness knowledge base. OpenAI `text-embedding-3-large` (3072 dim) is the Phase 0 baseline in `knowledge_chunks.embedding`. Voyage biomedical (1024 dim) is the quality experiment: does domain-specialised embedding improve recall for medical literature vs. the generic model?

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: `POST /v1/embeddings` with `model: voyage-3-biomedical` (or the latest biomed model).

## Authentication

- `Authorization: Bearer $VOYAGE_API_KEY`
- Secret: `VOYAGE_API_KEY` env var; config under `services.voyage.*`

## Error handling expectations

_To be filled in by the first implementer._ Embedding failures should fall back to the OpenAI baseline, not block ingestion.

## Cost and quota notes

- Pricing: https://www.voyageai.com/pricing — per 1M tokens, currently cheaper than OpenAI's `text-embedding-3-large`.

## Project-specific notes

- **If adopted, added as a parallel `embedding_voyage` column against the eval harness, not a schema swap** (per Phase 0 ADR). A/B evaluation on the same query set decides whether to promote it as the default.
- Dim mismatch matters: 1024 vs 3072 means queries must embed with the same model that populated the column being searched.
- Ingestion-only dependency — no user-path calls, so latency SLO is lax.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
