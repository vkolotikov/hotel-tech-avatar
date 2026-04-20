# ADR: Phase 0 Schema Migration — Multi-Vertical Foundations

**Date:** 2026-04-19
**Status:** Accepted
**Supersedes:** n/a

## Context
Evolving single-vertical hotel chat schema into multi-vertical expert-avatar platform per PROJECT_SPEC v4.0.

## Decision
- Add `verticals` table as first-class grouping.
- Extend `agents`, `conversations`, `messages` additively — no destructive changes.
- Create: `agent_prompt_versions`, `knowledge_documents`, `knowledge_chunks` (with pgvector `vector(3072)` embedding column; ANN index deferred to Phase 1 — 3072 dims exceeds the HNSW `vector_l2_ops` 2000-dim cap so the index type is picked against eval harness results), `external_source_cache`, `message_citations`, `verification_events`, `llm_calls`, `red_flag_events`, `token_usage_daily`, `user_profiles` (wide typed + `profile_metadata` jsonb), `subscription_plans`, `subscription_entitlements`.
- All existing hotel rows backfilled to vertical `hotel`; all new rows in vertical `wellness` (flipped active on mobile launch).
- Embedding dimensionality: `vector(3072)` (OpenAI `text-embedding-3-large`). Voyage 1024 is a Phase 1+ quality experiment; if adopted it will be added as a parallel column against the eval harness rather than a schema swap.
- Billing: vendor-neutral columns only (`billing_provider`, `billing_customer_id`, `billing_metadata` jsonb). No vendor names in column names.
- Local dev: Postgres required everywhere (no SQLite fallback). `docker-compose.yml` ships alongside the schema. Host port `5433` maps to container `5432` to avoid collisions with native Postgres installs.
- OpenAI ZDR: dashboard sharing disabled org-wide; formal contractual ZDR requested 2026-04-19 and gates Phase 3 user data. State recorded in `docs/compliance/openai-zdr.md`.

## Consequences
- Positive: hotel flow untouched; wellness pipeline has every table it needs for Phase 1–4 without further schema churn; prompt versioning enables admin A/B from day one.
- Negative: Postgres is required everywhere (local, CI, staging, prod). SQLite is no longer a supported dev DB. Mitigated by shipping `docker-compose.yml` so bring-up is one command.
- Follow-ups: eval harness tables (`eval_datasets`, `eval_runs`, `eval_results`) deferred to Phase 0 eval plan. Multimodal asset tables (`user_media_assets`, `wearable_samples`) deferred to Phase 5.

## Rollback
Each migration has a reversible `down()`. Full rollback sequence tested in `tests/Feature/SchemaRollbackTest.php`.
