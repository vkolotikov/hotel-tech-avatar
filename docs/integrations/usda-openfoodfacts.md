# USDA FoodData Central + Open Food Facts

**Status:** `planned` — wellness vertical nutrition sources
**Last verified:** 2026-04-20
**Official docs:**
- USDA FoodData Central: https://fdc.nal.usda.gov/api-guide
- Open Food Facts: https://openfoodfacts.github.io/openfoodfacts-server/api/

## What we use it for

Nutrition data for Nora (nutrition avatar) and Hans (hotel culinary). USDA FoodData Central is the authoritative source for macro + micronutrient data on US foods; Open Food Facts covers branded/packaged foods globally by barcode. Used both for ingestion into the knowledge base and for live lookups (e.g., user photographs a product barcode).

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated:

- **USDA FDC**:
  - `GET /v1/foods/search?query=...&api_key=...`
  - `GET /v1/food/{fdcId}?api_key=...`
- **Open Food Facts**:
  - `GET /api/v2/product/{barcode}.json` — no auth for read
  - `GET /api/v2/search?...` for query

## Authentication

- **USDA FDC**: `api_key` query param, free with registration — `USDA_FDC_API_KEY` env var
- **Open Food Facts**: read is anonymous; contributions require an OFF account. No auth needed for our read-only use.

## Error handling expectations

_To be filled in by the first implementer._ Open Food Facts quality is crowdsourced — missing fields are common, and some entries are stubs. Fallback to USDA or mark low-confidence.

## Cost and quota notes

- Both APIs are free for our expected volume.
- USDA rate limit: 1000 req/hour/key — practically unlimited for app use.

## Project-specific notes

- `external_source_cache.provider` values: `usda_fdc`, `openfoodfacts`
- `external_source_cache.external_id` format: `FDC:{fdcId}` for USDA, `OFF:{barcode}` for Open Food Facts
- Nutrient units + per-100g normalisation must be done at ingestion — downstream code should see a consistent schema.
- Barcode photo → barcode extraction happens client-side on mobile; only the extracted barcode hits our API.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
