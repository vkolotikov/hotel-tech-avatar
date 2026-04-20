# Cohere (Rerank)

**Status:** `planned`
**Last verified:** 2026-04-20
**Official docs:** https://docs.cohere.com

## What we use it for

Cross-encoder **reranker** (Rerank 3.5) for the wellness RAG pipeline. After pgvector returns top-K chunks by cosine similarity, the reranker rescores them against the query to produce a better top-N for generation. Classic bi-encoder-recall → cross-encoder-precision pattern.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated: `POST /v1/rerank` with `model: rerank-english-v3.5` (or the latest 3.5 successor).

## Authentication

- `Authorization: Bearer $COHERE_API_KEY`
- Secret: `COHERE_API_KEY` env var; config under `services.cohere.*`
- **ZDR equivalent:** Cohere enterprise data-handling agreement required before user queries flow.

## Error handling expectations

_To be filled in by the first implementer._ Rerank is on the critical path — must have a strict timeout + fallback to bi-encoder-only top-N, not block the reply.

## Cost and quota notes

- Pricing: https://cohere.com/pricing — per 1k search units
- Rerank cost goes to `llm_calls` with `purpose=rerank`.

## Project-specific notes

- **Tiny Python microservice** (per `CLAUDE.md`) is the likely caller, not Laravel directly — reranker latency + PHP's HTTP model make it a poor fit for synchronous call chains.
- Alternatives evaluated in the RAG pipeline brief: Jina AI reranker, cross-encoder self-hosted.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
