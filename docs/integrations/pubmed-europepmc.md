# PubMed + Europe PMC + Cochrane + Semantic Scholar

**Status:** `planned` — wellness vertical literature-retrieval sources
**Last verified:** 2026-04-20
**Official docs:**
- PubMed E-utilities: https://www.ncbi.nlm.nih.gov/books/NBK25501/
- Europe PMC REST: https://europepmc.org/RestfulWebService
- Cochrane Library API: https://www.cochranelibrary.com (contact for API access — no public self-serve)
- Semantic Scholar API: https://api.semanticscholar.org

## What we use it for

Scientific-literature grounding for the wellness vertical. Every factual claim in a wellness response must carry a citation; these are the primary evidence sources per `CLAUDE.md` and `docs/PROJECT_SPEC.md`. Cached rows land in `external_source_cache` so repeated lookups don't hammer the public APIs.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated:

- **PubMed E-utilities** (free, no key required below rate limits):
  - `GET /entrez/eutils/esearch.fcgi?db=pubmed&term=...&retmode=json`
  - `GET /entrez/eutils/efetch.fcgi?db=pubmed&id=...&rettype=abstract&retmode=xml`
  - With API key: up to 10 req/s vs. 3 req/s without
- **Europe PMC**:
  - `GET /webservices/rest/search?query=...&format=json`
  - `GET /webservices/rest/{source}/{id}/fullTextXML`
- **Cochrane**: contact Cochrane for institutional API access — likely a separate ingestion pipeline, not live lookup
- **Semantic Scholar**:
  - `GET /graph/v1/paper/search?query=...`
  - `GET /graph/v1/paper/{paper_id}/references`

## Authentication

- **PubMed**: optional `api_key` query param — register at https://www.ncbi.nlm.nih.gov/account/
- **Europe PMC**: no key required
- **Semantic Scholar**: optional `x-api-key` header for rate-limit uplift
- **Cochrane**: institutional subscription + API agreement
- Secrets: `NCBI_API_KEY`, `SEMANTIC_SCHOLAR_API_KEY` env vars

## Error handling expectations

_To be filled in by the first implementer._ Free-tier rate limits are the main gotcha: PubMed enforces strictly, and exceeding them gets an IP-level block.

## Cost and quota notes

- PubMed + Europe PMC: free with rate limits
- Semantic Scholar: free tier available, commercial use needs a plan
- Cochrane: paid institutional licence

## Project-specific notes

- `external_source_cache.provider` values: `pubmed`, `europepmc`, `cochrane`, `semanticscholar`
- `external_source_cache.external_id` format: `PMID:12345` for PubMed, `PMC123456` for Europe PMC full-text, `10.xxxx/...` for DOI-primary sources
- **Full-text availability is uneven** — PubMed gives abstracts reliably, full text requires Europe PMC's OA subset or author's institutional access. Grounding must tolerate abstract-only sources.
- Evidence-grade classification happens at ingestion time and lands in `knowledge_documents.evidence_grade` (e.g. `systematic-review`, `rct`, `observational`, `case-report`, `expert-opinion`).

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
