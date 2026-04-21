# Knowledge Retrieval (Hybrid Cached + Live) — Design Spec

**Date:** 2026-04-21
**Phase:** 1, sub-project #2 of 6
**Status:** Draft — awaiting user review

## Goal

Enable Nora (and later avatars) to retrieve wellness-focused knowledge from structured APIs (USDA, PubMed, Open Food Facts) via a hybrid model: cached data for fast responses, live API calls for rare/high-risk topics. Establish a **source-agnostic retrieval abstraction** so new avatars in Phase 2+ can plug in their own knowledge sources without code changes. This sub-project is the foundation for retrieval; verification and citation validation follow in later sub-projects.

## Non-Goals

- Full verification pipeline (claim extraction, grounding check, critic, citation validator) — sub-project #4.
- Knowledge ingestion from PDFs or other unstructured documents — Phase 2 exploratory task.
- Vector similarity tuning, reranking, or advanced retrieval (Colbert, dense passage retrieval) — Phase 2+ optimizations.
- LLM-as-judge scoring on retrieval quality. Deterministic assertions only in Phase 1.
- Offline-first or airplane-mode support. All avatars assume internet connectivity.

## Scope — What This Sub-Project Produces

1. **Driver abstraction** (`app/Services/Knowledge/Drivers/DriverInterface.php`) — each API (USDA, PubMed, Open Food Facts, etc.) has a normalizing driver that returns consistent `RetrievedChunk` objects with content, source_url, citation_key, evidence_grade, fetched_at.
2. **Three concrete drivers** (Phase 1):
   - `app/Services/Knowledge/Drivers/Usda/FoodDataDriver.php` — USDA FoodData Central API, nutrition + food composition.
   - `app/Services/Knowledge/Drivers/PubMed/SearchDriver.php` — PubMed search (abstract + metadata), configurable query per avatar.
   - `app/Services/Knowledge/Drivers/OpenFoodFacts/FoodSearchDriver.php` — Open Food Facts API, food labels + ingredients.
3. **Per-avatar knowledge source configuration** — `agents.knowledge_sources_json` (new JSONB column) declares which drivers are active, which are cached vs live, and per-driver config (API key, search query, rate limits).
4. **Nightly sync job** (`app/Jobs/SyncKnowledgeSources.php`) — pulls cached sources, normalizes via drivers, stores in `knowledge_documents` / `knowledge_chunks` with pgvector embeddings.
5. **Retrieval service** (`app/Services/Knowledge/RetrievalService.php`) — at generation time, vector-searches cached chunks, optionally calls live APIs for high-risk topics (drug interactions, clinical queries), combines results with deduplication.
6. **Schema migrations**:
   - Add `avatar_id` FK to `knowledge_documents` to partition by avatar.
   - Create `knowledge_sources` table (`id, agent_id, name, driver_class, config_json, cached, enabled, created_at, synced_at`) for audit and easy config queries (optional; can live in `agents.knowledge_sources_json` initially).
7. **Seeder update** — `NoraAvatarSeeder` is updated to populate Nora's `knowledge_sources_json` with her cached sources (USDA, PubMed nutrition) and optional live PubMed for drug interactions.
8. **Feature tests** — `RetrievalServiceTest` covers: vector search hit rate, live API fallback on cache miss, deduplication, timeout handling on live calls. Existing hotel smoke dataset unaffected.

## Exit Criteria For This Sub-Project

- All three drivers (USDA, PubMed, Open Food Facts) successfully normalize API responses to common `RetrievedChunk` shape.
- `php artisan knowledge:sync` completes for Nora, populates `knowledge_documents` and `knowledge_chunks` with ~500–1000 chunks across the three sources.
- Nora's `knowledge_sources_json` is seeded and loaded correctly.
- Vector search returns relevant chunks at generation time (manual test: query "protein requirements" returns nutrition facts; "magnesium sleep" returns PubMed abstract).
- Live API call is triggered correctly on high-risk keywords (e.g., "warfarin") and times out gracefully if slow.
- Nora's eval dataset (from sub-project #1) runs with retrieval context; citation assertions pass on at least 2 of 3 citation-required cases.
- The abstraction is clean enough that a Phase 2 avatar (e.g., Integra) can be added by writing a new driver and a new avatar config, with zero changes to retrieval service or job.
- Full backend test suite remains green. Hotel vertical unaffected.

## Architecture

### Retrieval Model — Hybrid Cached + Live

```
User prompt to Nora
    ↓
RetrievalService.retrieve($prompt, $avatar)
    ↓
    ├─→ Vector search pgvector (knowledge_chunks filtered by avatar_id)
    │   ├─→ Match found (cosine > 0.7) → use cached results
    │   └─→ No match or low confidence → check if high-risk topic
    │
    └─→ High-risk topic detected? (keywords: drug, warfarin, interaction, etc.)
        └─→ Trigger live API call to configured live source (e.g., PubMed)
            ├─→ Success → combine with cached, deduplicate
            └─→ Timeout/failure → log, proceed with cached only
    ↓
Return RetrievedContext (chunks + metadata)
    ↓
LlmClient.chat(systemPrompt, userMessage, context)
    ↓
Response with citations
```

### Driver Abstraction

Each driver implements `DriverInterface`:

```php
interface DriverInterface
{
    public function fetch(array $config): array; // returns RetrievedChunk[]
    public function name(): string;
}

class RetrievedChunk
{
    public function __construct(
        public readonly string $content,
        public readonly string $source_url,
        public readonly string $source_name,
        public readonly string $citation_key,      // PMID:12345 or https://...
        public readonly string $evidence_grade,    // research|guideline|database
        public readonly \DateTimeImmutable $fetched_at,
    ) {}
}
```

**USDA Driver** (`FoodDataDriver`):
- Calls USDA FoodData Central API `/search` with query on nutrients + foods.
- Normalizes each result to RetrievedChunk: content = food name + nutrient facts, source_url = USDA link, citation_key = USDA FDC ID.
- Evidence grade = "database".

**PubMed Driver** (`SearchDriver`):
- Calls PubMed E-utilities `/search` with configurable query (e.g., "nutrition AND health").
- Returns abstracts + metadata. Normalizes to RetrievedChunk: content = title + abstract, source_url = PubMed Central URL, citation_key = PMID, evidence_grade = "research".
- Config-driven: different avatars use different search queries (Nora: nutrition, Integra: clinical).

**Open Food Facts Driver** (`FoodSearchDriver`):
- Calls Open Food Facts API `/search` for food labels + ingredients.
- Normalizes to RetrievedChunk: content = product name + ingredient list + nutrition label, source_url = product page, citation_key = barcode or URL, evidence_grade = "database".

### Per-Avatar Configuration

New `knowledge_sources_json` column on `agents` table (added by migration). Shape (illustrative):

```yaml
knowledge_sources:
  - name: usda_fooddata
    driver: Usda\FoodDataDriver
    enabled: true
    cached: true
    config:
      api_key: env(USDA_API_KEY)
      search_categories: ["nutrition", "food_composition"]
      max_results_per_sync: 500
  
  - name: pubmed_wellness
    driver: PubMed\SearchDriver
    enabled: true
    cached: true
    config:
      api_key: env(PUBMED_API_KEY)
      search_query: "(nutrition OR diet OR wellness) AND (health OR benefit)"
      max_results_per_sync: 200
  
  - name: pubmed_drug_interaction_live
    driver: PubMed\SearchDriver
    enabled: true
    cached: false
    config:
      api_key: env(PUBMED_API_KEY)
      search_query: "drug AND (interaction OR contraindication)"
      max_results: 5
      timeout_sec: 3
  
  - name: open_food_facts
    driver: OpenFoodFacts\FoodSearchDriver
    enabled: true
    cached: true
    config:
      search_categories: ["food", "ingredients", "allergens"]
      max_results_per_sync: 300
```

Nora is seeded with the above (USDA, PubMed wellness cached, PubMed live for drug interactions, Open Food Facts). Integra (Phase 2) swaps the PubMed query to clinical and adds DrugBank.

### Nightly Sync Job

`app/Jobs/SyncKnowledgeSources.php` (queued):

1. Iterate all agents.
2. For each agent, iterate `knowledge_sources_json` where `cached: true`.
3. Load the driver class, instantiate with config.
4. Call `$driver->fetch($config)` → array of RetrievedChunk.
5. For each chunk:
   - Create `KnowledgeDocument` with `(avatar_id, source_name, source_url, citation_key, evidence_grade, synced_at, metadata)`. Use source_url as dedup key (update if exists, insert if new).
   - Split chunk.content into smaller chunks if needed (e.g., abstract → 2–3 chunks).
   - Generate embedding via `EmbeddingService::embed($chunk_content)` (calls OpenAI 3072-dim embedding API).
   - Create `KnowledgeChunk` with `(document_id, avatar_id, chunk_index, content, embedding, metadata_json)`.
6. Log: documents added/updated/skipped, total chunks, total tokens, cost (if tracked).
7. Clean up old docs: optionally retire documents not touched in 30 days.

### Generation-Time Retrieval

`RetrievalService::retrieve($prompt, $avatar)`:

1. Query pgvector: `SELECT * FROM knowledge_chunks WHERE avatar_id = $avatar_id ORDER BY embedding <-> $prompt_embedding LIMIT 5`.
2. If cosine similarity > 0.7 for at least 1 result, collect those chunks as context.
3. Check `$prompt` for high-risk keywords (regex: warfarin, ssri, drug, interaction, contraindication, etc.). If found, check if live API available for this avatar.
4. If live API available and cache hit was low confidence (or no hit):
   - Call live driver with strict timeout (3s default, configurable).
   - On success, merge with cached results (deduplicate by source_url).
   - On timeout/error, log, proceed with cached results only.
5. Return `RetrievedContext` object: `(chunks: RetrievedChunk[], total_tokens: int, retrieval_latency_ms: int)`.

**High-risk keyword list** (stored in config):
```php
'retrieval' => [
    'high_risk_keywords' => [
        'warfarin', 'ssri', 'maoi', 'metformin',
        'drug', 'medication', 'supplement.*interaction',
        'contraindic', 'clinical', 'diagnosis',
    ],
    'live_timeout_sec' => 3,
]
```

### Citation & Metadata

Each `RetrievedChunk` carries citation metadata. The generation prompt includes:
```
Every factual claim must cite its source. Use these formats:
- For research: [Citation: PMID:12345]
- For databases: [Citation: https://...] or [Citation: USDA FDC ID: 123456]
- Always include the source name and a date or version if available.
```

The evaluator checks that citations match known sources (Phase 1 uses regex; Phase 2+ uses `CitationValidator` microservice to verify URLs/PMIDs).

### Schema Changes

**Migration: Add avatar_id to knowledge_documents**
```sql
ALTER TABLE knowledge_documents ADD avatar_id BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE knowledge_documents ADD FOREIGN KEY (avatar_id) REFERENCES agents(id) ON DELETE CASCADE;
CREATE INDEX idx_knowledge_documents_avatar_id ON knowledge_documents(avatar_id);
```

**Optional: New knowledge_sources table** (for audit + easy querying)
```sql
CREATE TABLE knowledge_sources (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    driver_class VARCHAR(255) NOT NULL,
    config_json JSONB NOT NULL,
    cached BOOLEAN DEFAULT TRUE,
    enabled BOOLEAN DEFAULT TRUE,
    synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    UNIQUE KEY uk_agent_name (agent_id, name)
);
```

For Phase 1, we can store `knowledge_sources_json` on `agents` directly; the table is optional. Include it in the plan if querying "which sources does Nora use?" becomes frequent.

### Error Handling & Resilience

- **API timeout (live call):** Log at WARN level, continue with cached results. The response ships—just without fresh data. Example: `"Live PubMed call timed out after 3s; proceeding with cached results"`.
- **API error (rate-limit, authentication, 5xx):** Log, treat same as timeout. Retry logic is upstream (queue job can retry; generation-time live calls do not retry).
- **Sync job failure on one source:** Log and continue to next source. If all sources fail, the agent has no new documents, but existing cached documents remain available.
- **Vector search returns no results:** Return empty context. Response still generates (using system prompt + safety rules only). No error.
- **Embedding generation fails:** Log, skip that chunk, continue.

All errors are logged with structured metadata (avatar_id, source_name, error_code, latency_ms) for observability.

## Phase 1 Implementation Scope

**Avatars in Phase 1:** Nora only. Knowledge sources: USDA (cached), PubMed wellness (cached), PubMed drug interactions (live), Open Food Facts (cached).

**What Phase 2 introduces:** Integra (clinical PubMed + DrugBank), Luna (sleep research), Zen (mindfulness research), Axel (fitness + longevity), Aura (dermatology + cosmetics). Each gets a new config entry; no code changes.

**Existing hotel vertical:** Hotel concierge remains untouched. No knowledge sources configured for hotel agents. If hotel agents need retrieval later, it's a separate decision.

## Testing Strategy

- **Unit:** Driver implementations (USDA, PubMed, Open Food Facts) with mocked HTTP responses. Assert each driver normalizes correctly to `RetrievedChunk`.
- **Unit:** `RetrievalService` vector search and deduplication logic with fixture chunks.
- **Feature:** `RetrievalServiceTest` runs full flow: seed Nora with knowledge, retrieve by query, verify results + latency + cost.
- **Integration:** Sync job test with real API (or recorded responses) to verify end-to-end flow: fetch → normalize → store → embed → queryable.
- **Live smoke (manual, not CI):** `php artisan knowledge:sync --avatar=nora` runs nightly sync, outputs document count, chunk count, tokens, cost estimate. Output captured for baseline.
- **Eval integration:** Nora's 17-case dataset from sub-project #1 is re-run with retrieval context enabled. Citation assertions (sub-project #4 will formalize) check that responses cite sources.
- **No regressions:** Hotel smoke test remains green. Existing agents without knowledge sources continue to work (return empty context gracefully).

## Cost and Safety Notes

- **API cost:** USDA FoodData is free. PubMed is free. Open Food Facts is free. Daily sync is ~100–200 API calls total, negligible cost. Live calls during generation are rare (only on high-risk keywords), so incremental cost is minimal.
- **Latency:** Sync job runs offline; no impact on response time. Generation-time retrieval: pgvector search ~10ms, live API call ~3s if triggered (rare). Total latency added to generation: ~10–100ms median, ~3s on high-risk queries (acceptable; fallback graceful).
- **Safety:** Red-flag patterns (sub-project #1) fire before retrieval. Retrieved content is untrusted (same as user input); verification pipeline (sub-project #4) extracts and validates claims. Retrieval does not bypass safety.
- **Data retention:** Knowledge documents are sourced from public health databases. No user content is stored. Metadata (sync date, source) is retained indefinitely; chunks are subject to the same data-retention policy as the rest of the backend (TBD in compliance review, currently assume indefinite unless ZDR applies).

## Alternatives Considered

- **Embed on-the-fly (no caching).** Rejected — slower (embedding + vector search per query), higher cost, higher latency (unacceptable for response speed).
- **Single embeddings table, no avatar partitioning.** Rejected — cross-avatar noise in retrieval. Better to shard by avatar.
- **Hardcode API queries per avatar.** Rejected — violates "new avatars, no code changes" goal. Config-driven is more flexible.
- **Always live, no cache.** Rejected — Phase 1 would be too slow. Hybrid gives us both speed and freshness where needed.
- **Embedding generation by a separate microservice.** Rejected for Phase 1 — overkill. Offload to Python only if embedding latency becomes visible; start in-process via LlmClient.

## Consequences

- Nora now has rich, current wellness knowledge sourced from three authoritative APIs. Her responses are grounded in data, enabling citation.
- The abstraction is the blueprint for all future avatars. Integra, Luna, and the others can be added in Phase 2 with zero retrieval-engine code changes.
- Storage growth: ~500–1000 documents per avatar, ~2000–5000 chunks. At 3072-dim embeddings, ~50–100MB per avatar (pgvector compression helps). Manageable on current infra.
- Nightly sync is a new operational task; monitoring latency and error rates is important.
- Live API calls introduce latency variance on high-risk queries; timeouts are graceful, not fatal.
- Phase 2+ will likely add reranking and advanced retrieval (Colbert, dense passage retrieval) if vector-search precision drifts. This design accommodates it.

