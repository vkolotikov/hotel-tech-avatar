# CosIng + INCI Decoder

**Status:** `planned` — wellness vertical cosmetic-ingredient references
**Last verified:** 2026-04-20
**Official docs:**
- CosIng (EU Commission Cosmetic Ingredient Database): https://ec.europa.eu/growth/tools-databases/cosing/
- INCI Decoder: https://incidecoder.com/ (no official API — data via scraping or partnership)

## What we use it for

Ingredient references for Aura (skin/beauty). A user photographs a product label or types in an ingredient list; we decode what each ingredient does, flag known irritants/allergens, and ground any claim in the evidence base. Cosmetic claims are regulated differently from drug claims (educational is fine; medical efficacy is not) — scope rules on Aura reflect that.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated:

- **CosIng**: no public REST API — the EU provides a downloadable dataset; we ingest on a schedule
- **INCI Decoder**: no official API; if used, requires partnership or is replaced with the EU dataset

## Authentication

- CosIng: none (public dataset)
- INCI Decoder: TBD depending on partnership status; not safe to scrape at scale

## Error handling expectations

_To be filled in by the first implementer._ Ingredient lookup is not safety-critical in the same way drug interactions are — a miss degrades the response but doesn't endanger the user.

## Cost and quota notes

- CosIng: free (public sector data).
- INCI Decoder: partnership-contingent.

## Project-specific notes

- Ingestion-heavy, not live-lookup-heavy: CosIng is a bounded dataset that fits in `knowledge_documents` / `knowledge_chunks`. Re-ingest quarterly or on CosIng data updates.
- `external_source_cache.provider` values: `cosing`, `incidecoder` (if used)
- Users photographing product labels → OCR → ingredient list happens in the vision pipeline (Gemini), not here; this integration only decodes the already-extracted ingredient strings.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
