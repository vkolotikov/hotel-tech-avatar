# Phase 1 — Knowledge sync smoke test

Once [commit 6d01862](https://github.com/vkolotikov/hotel-tech-avatar/commit/6d01862) has deployed to Laravel Cloud and the PubMed / USDA keys are set in Secrets, run this once per avatar to prove the pipeline works.

## Prerequisites

- Backend deploy includes the new `SyncKnowledgeSources` code.
- `PUBMED_API_KEY` and `USDA_API_KEY` are set in Laravel Cloud **Environment → Secrets**.
- Avatar exists (e.g. Nora, id=2) and is published.

## Walkthrough (super-admin UI)

1. Open [avatars.hotel-tech.ai/admin](https://avatars.hotel-tech.ai/admin) and sign in.
2. Filter sidebar → **WellnessAI** → click Nora.
3. In the form, scroll to **Knowledge Sync**. Current state should read `Idle` or the last sync date.
4. Check that Nora has at least one source in the existing `knowledge_sources_json`. If not, add one via admin (currently super-admin would edit the JSON directly — the structured editor is Phase-2 polish). Example minimum shape:
   ```json
   [
     { "type": "pubmed", "key": "nora_pubmed_core", "search_query": "gut microbiome nutrition" },
     { "type": "usda",   "key": "nora_usda_core" }
   ]
   ```
5. Click **Reindex knowledge**. The request runs synchronously, so the page hangs for a few seconds while it fetches, embeds, and writes.
6. When it returns, the status chip should flip to `completed` and "files: N" should reflect the new chunk count. If it says `failed`, the tooltip shows which source broke.

## Walkthrough (server-side)

If you prefer to run from Laravel Cloud's console:

```bash
php artisan tinker --execute="dispatch_sync(new \App\Jobs\SyncKnowledgeSources(2));"
```

Or for all avatars at once (slower, uses more OpenAI credits):

```bash
php artisan tinker --execute="dispatch_sync(new \App\Jobs\SyncKnowledgeSources());"
```

## What to check afterwards

Expected rows (counts are examples, actual numbers depend on the drivers' `max_results_per_sync`):

```sql
-- Are documents there?
SELECT id, agent_id, title, source_url, evidence_grade
  FROM knowledge_documents
 WHERE agent_id = 2;

-- Are chunks embedded?
SELECT COUNT(*) AS chunk_count,
       AVG(LENGTH(content)) AS avg_content_len
  FROM knowledge_chunks
 WHERE agent_id = 2;

-- Sample content so you can eyeball quality.
SELECT content, metadata
  FROM knowledge_chunks
 WHERE agent_id = 2
 LIMIT 5;
```

Then, from the mobile app, open a chat with Nora and ask a question that should hit the corpus (e.g. "What does the research say about fibre and SCFAs?"). If retrieval is wired through `GenerationService` correctly, the reply should include citations matching the PMIDs or FDC IDs of the ingested chunks.

## If something fails

| Symptom | Likely cause | Fix |
|---|---|---|
| Status `failed`, error "source failed: OpenAI API key not configured" | `OPENAI_API_KEY` not set in Cloud | Add to Secrets, redeploy |
| Status `failed`, error mentions HTTP 429 | PubMed rate limit | Lower `PUBMED_RATE_PER_SECOND` or wait |
| Status `completed`, but `chunk_count` is 0 | Driver returned no results | Check the `search_query` — USDA default is "nutrition", PubMed accepts any term |
| Status `completed`, but chunks have all-zero embeddings | EmbeddingService caught a failure silently | Check `storage/logs/laravel.log` for `EmbeddingService: Failed to generate embedding` |
| Mobile chat reply doesn't cite anything | Retrieval path not yet connected in GenerationService | Expected if `RetrievalService` isn't called from generation yet — next Phase-1 wiring task |

## Known v1 limitations (documented, will address)

- **Sync dispatch is synchronous** on the reindex endpoint. Good for super-admin use; bad if you ever reindex a large corpus. Switch to `dispatch()` once a queue worker is confirmed.
- **Driver configuration is minimal** — the PubMed driver's `search_query` is per-source; USDA's is hardcoded to "nutrition" inside the driver. Expose as config when you need multiple avatars pulling different USDA subsets.
- **No delta sync** — every reindex re-fetches and re-embeds everything. Fine for small corpora; wasteful for large. Add a `checksum` check per chunk later.
- **No retrieval loop verification** yet — we only prove the chunks land in the DB. The next step is adding retrieval into `GenerationService` so they're actually used.
