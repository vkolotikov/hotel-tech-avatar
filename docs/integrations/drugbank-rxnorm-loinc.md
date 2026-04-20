# DrugBank + RxNorm + LOINC

**Status:** `planned` — wellness vertical clinical references
**Last verified:** 2026-04-20
**Official docs:**
- DrugBank API: https://docs.drugbankplus.com
- RxNorm (NLM): https://lhncbc.nlm.nih.gov/RxNav/APIs/RxNormAPIs.html
- LOINC: https://loinc.org/ (search via NLM's UMLS or direct download)

## What we use it for

Clinical references for Dr. Integra (functional medicine) and any avatar that needs to reason about medications, interactions, or lab tests. **This is where the prescription-drug hard-rule is enforced in code**: the models can _look up_ a drug's interactions and mechanism of action to explain what a user has been prescribed by their doctor, but they cannot dose, prescribe, or recommend starting/stopping a drug. That guardrail lives in the scope + red-flag rules on each agent, but the data source still matters.

## Endpoints this project calls

_None yet._ First implementer fills this in. Anticipated:

- **DrugBank**: `GET /product_concepts/{id}/drug_interactions` and related endpoints (licensed API)
- **RxNorm**: `GET /REST/rxcui.json?name=...` (free, NLM-hosted)
- **LOINC**: bulk download + local lookup is typical; the FHIR terminology service is the live option

## Authentication

- **DrugBank**: paid institutional licence + API key — `DRUGBANK_API_KEY` env var
- **RxNorm**: free, no auth
- **LOINC**: account required to download; no auth needed for derived local lookup

## Error handling expectations

_To be filled in by the first implementer._ Drug-interaction lookups are safety-critical: failure to resolve must block the response, not silently skip.

## Cost and quota notes

- **DrugBank**: paid — tier negotiation required before launch, and the line-item cost should be modelled in the wellness vertical's per-message cost envelope.
- RxNorm + LOINC: free.

## Project-specific notes

- `external_source_cache.provider` values: `drugbank`, `rxnorm`, `loinc`
- **Severe drug interactions are a deterministic red-flag rule** per `CLAUDE.md` §"Hard rules" — the check happens before any LLM generation; it is not an LLM decision.
- `red_flag_events` with `rule_slug='drug-interaction-severe'` are logged whenever DrugBank returns a major-severity interaction on any medication in `user_profiles.medications` + whatever the conversation mentions.
- Medication names from users are untrusted input (prompt-injection surface) — canonicalise through RxNorm before any downstream use.

## Change log

- 2026-04-20 — stub created as part of Phase 0 integrations scaffold
