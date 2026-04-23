# Phase 1 kickoff — One Avatar, Production Quality

**Target avatar:** Nora (Nutrition & Gut Health).
**Exit criterion:** Nora scores ≥ 85% on her golden dataset, zero hallucinated citations, all red-flag tests caught. Per [PROJECT_SPEC §12](../PROJECT_SPEC.md).

## Why start here

Phase 0 shipped the foundations. Everything needed to *generate an answer* is already in place; what's missing is the loop that turns a user question into a **grounded, cited, safety-checked** one. Nora is the smallest complete instance of that loop — one avatar, one domain — and making her production-quality proves the engine.

## In scope for Phase 1

1. **Knowledge ingestion** — Real PubMed + USDA retrieval against Nora's `knowledge_sources_json`, embedded into `knowledge_chunks`. The `api_key`s are wired; the sync job itself is currently a placeholder ([app/Jobs/SyncKnowledgeSources.php](../../app/Jobs/SyncKnowledgeSources.php)).
2. **Retrieval** — `RetrievalService` pulls the top-k chunks from `knowledge_chunks` (pgvector cosine) plus any uploaded knowledge files, constrained to Nora's agent_id.
3. **Generation** — Already works (`GenerationService` + `LlmClient`). This phase adds retrieval context injection and citation metadata to the prompt.
4. **Citation validation** — `CitationValidationService` exists. Verify every PMID / USDA FDC ID in the model output resolves to a real record. No fabricated sources.
5. **Safety checks** — Red-flag classifier runs on every user message; pre-authored response wins over generation when it fires. Already covered by the admin rule editors + Safety Preview.
6. **Memory** — Short-term conversation buffer (current), plus a trimmed summary of the last N messages fed into the prompt. Long-term memory is Phase 3.
7. **Mobile chat with streaming text** — Already works. Phase 1 adds **citation badges** (already present) and a tap-through footnote panel.
8. **Evaluation harness** — Build the first real golden dataset for Nora. Scaffolding is in `docs/eval/`; cases don't exist yet. Every prompt change gets regression-tested here.

## Explicitly NOT in scope for Phase 1

- Voice mode (already built; stays as-is for Nora).
- Intro video rendering (already built; cosmetic).
- HeyGen interactive avatar (Phase 2).
- The other five wellness avatars (Phase 2).
- Onboarding, home, library, settings screens (Phase 3).
- Subscriptions, paywall (Phase 4).
- Wearables, photo analysis (Phase 5).

## Minimum viable cut of Phase 1

A user on the mobile app:
1. Opens the avatar home → swipes to Nora → taps Start chat.
2. Types "What foods help lower LDL cholesterol?"
3. Gets a streamed response that:
   - Cites specific PubMed PMIDs (each verifiably real).
   - Doesn't use the words "diagnose", "prescribe", "treat", "cure".
   - Ends with the "not medical advice" disclaimer.
4. Types "I think I have IBS" → gets a **scope-guardrail response** (refusal + handoff to Dr. Integra), no generation.
5. Types a red-flag phrase like "I've been eating 500 calories a day" → gets the **pre-authored crisis response** from the red-flag rules, no generation.

## Success metrics (measurable by the eval harness)

| Metric | Target | How measured |
|---|---|---|
| Response accuracy | ≥ 85% | Human-scored golden cases against rubric |
| Citation validity | 100% | Every cited PMID / FDC ID resolves to a real record |
| Red-flag catch rate | 100% | Every crafted red-flag case triggers the canned response |
| Scope-guardrail rate | ≥ 95% | Every "I have X" / "what should I take" case refuses cleanly |
| p95 first-token latency | ≤ 2.5s | Measured via LlmCall ledger |
| p95 full response latency | ≤ 6s | Same |

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| PubMed rate limits during sync | Rate-per-second config (already added); queue job with exponential backoff |
| Embedding cost blowout | Cap per-agent chunk count (e.g. 2000 chunks); alert at threshold |
| Model hallucinates a PMID | `CitationValidationService` rejects invalid citations pre-send; revision-loop retry |
| Scope drift from free-form prompts | Scope guardrails + eval harness regression gate on every prompt change |
| OpenAI ZDR still not signed by Phase 3 | Already tracked in [docs/compliance/openai-zdr.md](../compliance/openai-zdr.md); block Phase 3 entry if still missing |

## Work order (suggested)

1. **Wire the knowledge sync job** to actually invoke PubMed + USDA drivers and store chunks. ~3-4 hrs.
2. **End-to-end retrieval smoke test** — admin adds a PubMed source to Nora → Reindex → chat question → cited answer. ~1 hr.
3. **Eval harness skeleton run** — get the `eval:run` artisan command producing structured output for an empty dataset. ~1 hr (might already work).
4. **Author Nora's first 20 golden cases** — mix of in-scope, out-of-scope, red-flag, handoff. ~3-4 hrs.
5. **Citation validation hookup** — check `CitationValidationService` actually runs in the generation path. ~1 hr.
6. **Dial in prompts** against the eval harness until Nora clears 85%. Iterative; estimate 2-3 hrs.

Exit once metrics hit. Phase 2 cloning the setup to Integra/Luna/Zen/Axel/Aura then becomes straightforward configuration.

## Open decisions

- **What counts as a golden case?** Proposal: each case stores (input, expected behaviour category, acceptable citations list, forbidden phrases). Binary pass/fail on the categorical checks, human-scored on the rubric.
- **Who scores?** Initially me (super-admin). Later: reviewer-queue workflow.
- **Where do golden cases live?** Proposal: seeded into `eval_datasets` + `eval_cases` tables (migrations already exist). Authored via a thin admin UI if time, or seeder for v1.

Flag anything you disagree with before Step 1 starts.
