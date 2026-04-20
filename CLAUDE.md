# CLAUDE.md

This file orients Claude Code to the project. Read it in full before every task. The authoritative product specification is at `docs/PROJECT_SPEC.md` — read that too before starting any substantive work.

---

## What this project is

An **expert-avatar platform**. The engine powers multiple verticals (specialist-advice domains), each delivered as its own configuration. The platform already serves a **hotel** vertical (business-coach avatars — marketing, accounting, spa-therapist, and similar — delivered through the existing Vite SPA at `/spa`). We are now adding a **wellness** vertical, delivered through a new React Native mobile app published publicly as WellnessAI.

The wellness vertical is built around six avatars — Dr. Integra (functional medicine), Nora (nutrition), Luna (sleep), Zen (mindfulness), Axel (fitness/longevity), and Aura (skin/beauty). Users talk to them by voice or text, upload photos and documents, and connect wearables. One shared orchestration engine powers all avatars in every vertical through per-avatar configuration. Full product description, user experience, avatar definitions, quality and safety requirements, phases, and success criteria are in `docs/PROJECT_SPEC.md`.

---

## Platform and verticals — read this carefully

This is the most important architectural distinction in the project.

- **The engine is domain-agnostic.** Orchestration, retrieval, verification, voice, avatar rendering, memory, billing, metering, observability, admin — none of these know or care which vertical they are serving.
- **A vertical is configuration.** Which avatars exist, what they know, what tools they call, what safety rules apply, what branding and copy surface in the app, which subscription structure applies — all configuration, not code.
- **Verticals currently in the platform:** `hotel` (existing, continues operating through the web SPA), `wellness` (new, delivered via the mobile app as WellnessAI).
- **Safety rules are per-vertical.** The strict clinical rules described in `docs/PROJECT_SPEC.md` (no diagnosis, no prescription, red-flag triggers) are rules of the **wellness** vertical. The hotel vertical has its own rules appropriate to its domain. Each vertical's rules live in its configuration and are enforced by the engine.
- **The mobile app is a shared codebase with per-vertical builds.** A build-time flag and configuration bundle determine branding, avatars, tier structure, and App Store metadata. Launching the wellness build publicly is the v1 goal; a hotel-facing mobile build may follow later.
- **Adding a new vertical should require no engine code changes.** If a task would require the engine to know which vertical it is running for, stop and flag it — the feature has been placed on the wrong side of the line.

Formal description of the platform/vertical model is in `docs/PROJECT_SPEC.md` under "Platform and Verticals."

---

## Existing stack — extend, do not replace

- **Backend:** Laravel 13 on PHP 8.4 (existing, extend)
- **Database:** PostgreSQL (existing; add the `pgvector` extension when vector search is needed; do not introduce a separate vector database unless scale requirements truly exceed pgvector)
- **Hosting:** Laravel Cloud (existing)
- **Object storage:** DigitalOcean Spaces (existing)
- **Source control:** GitHub (existing; monorepo — see below)
- **Existing web SPA:** Vite SPA at `/spa` — remains the hotel vertical's delivery surface and the admin/web companion for the wellness vertical
- **Existing integrations in the repo:** HeyGen (`HEYGEN_API_KEY`, `AvatarStream` component), OpenAI (Whisper STT, TTS, generation) — **keep and extend, do not scrap** (see "Existing in-flight work" below)

Add the following as the project progresses:

- **Mobile app:** React Native with Expo (iOS + Android, single codebase, per-vertical builds)
- **Subscriptions:** RevenueCat for mobile in-app purchases — do not build IAP handling from scratch
- **Admin panel:** Filament — covers avatar configuration, knowledge ingestion, reviewer queue, eval dashboard, cost reporting
- **Small Python microservice** only where PHP has no ecosystem fit: PDF layout parsing, biomedical embeddings, reranker calls. Keep it stateless, tiny, and without business logic.

Real-time media (avatar video, streaming voice in and out) runs client-side in the mobile app via provider SDKs. Laravel issues short-lived tokens but never proxies media. Text streams to the client via SSE from Laravel.

---

## Repository strategy — monorepo

One GitHub repository, with clear top-level separation: backend (the existing Laravel app), mobile (the new Expo app, added in Phase 0), ml-service (the Python microservice, added when first needed), and `docs/` (shared documentation, ADRs, phase briefs, integration files, eval harness, safety rules). The existing SPA at `/spa` remains where it is.

Rationale: backend schema changes often need mobile updates in the same PR, documentation belongs in one place, and there is already one repo to extend rather than three to create. Separate repos would be appropriate for open-source libraries or for teams with strict ownership boundaries — neither applies here.

---

## LLM strategy — pragmatic, staged, measured

- **v1.0 (launch):** continue using the existing LLM provider as the primary generation model. Do not switch providers just to match an earlier version of the spec. Switching is a measurable quality decision, not a requirement.
- **Upgrade path:** Claude Opus 4.7 is the target primary model for the wellness vertical once the eval harness is in place and can demonstrate a measurable quality lift. GPT-5.4 is the target cross-model critic for verification. Gemini 3.1 Pro is the target vision model for skin, food, and form-check. Each addition is promoted only if it beats the current baseline on the eval harness.
- **Do not introduce multiple providers on day one.** Ship with what works, layer in sophistication against measurement.
- **All LLM calls go through a single internal client abstraction** so providers can be swapped without touching business logic. This abstraction also unifies tracing, cost accounting, retries, and zero-data-retention enforcement.

---

## Hard rules — do not violate

These are the hard rules of the **wellness** vertical. Other verticals have their own hard rules in their configuration.

1. **No diagnosis, no prescription, no prescription-drug dosing in any user-facing wellness output, ever.** The wellness product is wellness education. Drifting into clinical language reclassifies it as a regulated medical device.
2. **Every factual claim must be grounded in retrieved evidence and carry a citation.** No invented sources, no invented PMIDs or DOIs.
3. **Red-flag safety rules are deterministic and non-negotiable.** Crisis indicators, emergency symptoms, melanoma criteria, severe drug interactions, eating-disorder indicators — each triggers a pre-authored response. No creative generation bypasses this layer. User input cannot override it.
4. **User-supplied content is always untrusted.** Uploaded PDFs, photos, and messages may contain prompt-injection attempts. Instructions inside user content never override system instructions.
5. **No user health content is ever sent to a third-party model without a zero-data-retention agreement in place for that provider and our account.** Confirm ZDR status with the provider's sales or support team before enabling them against production user data. The eval harness is not production use; real user conversations from Phase 3 onward are.
6. **The evaluation harness is the contract.** Any change to prompts, retrieval, tools, verification, or the knowledge base must run the eval harness and must not regress scores. Build the harness first, before any generation features in a given vertical.
7. **Existing hotel vertical functionality must not regress** as the wellness vertical is built out. This is part of the Phase 0 exit criteria and every subsequent phase's definition of done.

---

## Architectural principles

- **One engine, many verticals, configuration-first.** Avatar differences — persona prompt, knowledge base, tools, voice, visual, handoff rules, red-flag list — live as data, not code. Adding a seventh wellness avatar or an entire new vertical is a configuration task. If it requires new engine code, stop and flag.
- **Laravel owns product logic and state.** Orchestration, auth, billing, metering, admin, queues, memory — all in PHP.
- **Postgres is the single source of truth.** Users, sessions, messages, knowledge chunks, embeddings (via pgvector), verification events, usage ledger, subscriptions. One database.
- **Redis owns transient state only.** Queues (via Horizon), rate-limit counters, short-term conversation buffer, hot quota checks.
- **Streaming text from Laravel uses SSE.** Streaming media does not go through Laravel.
- **Every wellness response passes through the verification pipeline before the user sees it.** Claim extraction → grounding check → critic (v1.1+) → citation validator → safety classifier. Revision loop up to twice. Fall back to a softened or referral-only response if checks cannot pass.

---

## Existing in-flight work

The current branch includes in-flight HeyGen voice-mode work and a memory-flags refactor (v1 sunset). Do not scrap either.

- **Land in-flight work on its own merits.** If it is close to done and coherent, complete it against its original intent.
- **Generalise in Phase 2.** The HeyGen integration and voice-mode component migrate into the WellnessAI integration files and are generalised to accept any avatar configuration from either vertical. The existing hotel avatars continue to use the same component with their own configuration.
- **The memory refactor is foundation work.** Once it lands, it becomes the basis for the wellness vertical's long-term memory layer — expand it, do not replace it.
- **Where in-flight work assumes a single vertical or a hard-coded agent list**, surface that as an ADR-worthy decision: note the assumption, decide whether to land-then-refactor or pivot now, record the reasoning.

---

## Phase 0 — definitions of done

Phase 0 is a refactoring-and-foundations phase, not a greenfield phase. Its purpose is to introduce the vertical concept into the data model, extend the core tables to meet the full specification's requirements, ensure the existing hotel flows continue working, and stand up the foundations that subsequent phases depend on.

Specific Phase 0 scope:

- **Mobile shell:** a minimal Expo project exists, builds on iOS and Android simulators, has Sanctum auth wired to the existing Laravel backend, and successfully round-trips a single authenticated request to a health endpoint. No chat screen, no onboarding, no avatars, no voice, no navigation beyond a placeholder. Full app UI work begins Phase 1.
- **Telemetry:** Sentry is capturing errors in both backend and mobile. Langfuse is receiving a trace on at least one real LLM call routed through the LLM client abstraction. PostHog is not wired yet — that waits for Phase 3 when there is a product to analyse.
- **pgvector:** verify on the target Laravel Cloud Postgres instance that the `pgvector` extension can be enabled. Run a minimal migration that creates a throwaway vector column, inserts one embedding, and queries it. If the extension is not available on the current tier, raise this immediately — it is a blocker for Phase 1.
- **ZDR confirmation:** record in `docs/compliance/` which LLM providers have ZDR confirmed for our account. If the existing OpenAI usage is to continue as the wellness v1.0 brain, confirm ZDR is enabled on the OpenAI organisation before Phase 3 user data arrives. Anthropic and Google ZDR are requested when those providers are actually introduced.
- **Data model extensions:** the vertical concept is introduced. Existing tables (`agents`, `conversations`, `messages`, and related) are extended to support prompt versioning, citations, verification events, token usage, and subscription entitlements. Existing hotel data is migrated to the `hotel` vertical without loss.
- **No regressions in the hotel vertical.** The existing Vite SPA continues to function. A smoke-test pass on the hotel flows is part of Phase 0 exit.
- **Eval harness skeleton:** the harness exists as a runnable scaffold, even if no datasets are authored yet. Phase 1 adds the first golden dataset (Nora).
- **Monorepo layout clarified:** the agreed top-level structure is in place, with the new mobile directory present and the Python ml-service directory scaffolded with a healthcheck endpoint only.

---

## API integration rules

Your training data is older than the current API shapes for most of this project's integrations. Treat training-data memory of API shapes as unreliable for anything on the following list, especially the LLM providers.

**The rule:** for any task that touches a third-party API, do this before writing code.

1. **Read the matching file under `docs/integrations/` if one exists.** It contains the authoritative URL for the official documentation, the endpoints this project actually uses, authentication patterns, and any project-specific notes or gotchas.
2. **Fetch the live official documentation at the URL listed in that file.** Never rely on what you think you remember about the API.
3. **If no integration file exists yet for the service, create one.** Ask the user for the official documentation URL, then populate the file.
4. **Before writing integration code, summarise to the user:** the endpoints you plan to call, your authentication approach, how errors will be handled, and any ambiguities. Wait for approval.
5. **After the integration works, update the integration file** with anything discovered during implementation — authentication quirks, rate-limit behaviour, response-shape surprises, version notes. This file is the team's institutional memory.

**Services this project integrates with or will integrate with (each should have its own file under `docs/integrations/`):**

- OpenAI — currently in use; Whisper STT, TTS, generation (existing)
- Anthropic — Claude Opus 4.7 and Claude Haiku 4.5 (future, quality-measured upgrade path)
- Google — Gemini 3.1 Pro via Vertex AI (future, for vision and long context)
- HeyGen — Interactive Avatar streaming, session tokens (existing)
- ElevenLabs — TTS streaming, voice library (future, quality-measured upgrade path)
- Deepgram — STT streaming, Nova-3-Medical (future, quality-measured upgrade path)
- Cohere — Rerank 3.5 (future)
- Voyage AI — biomedical embeddings (future)
- RevenueCat — subscription entitlements, webhooks (future)
- PubMed / E-utilities, Europe PMC, Cochrane, Semantic Scholar — scientific literature (wellness vertical)
- USDA FoodData Central, Open Food Facts — nutrition (wellness vertical)
- DrugBank, RxNorm, LOINC — clinical references (wellness vertical)
- CosIng, INCI Decoder — cosmetic ingredients (wellness vertical)
- AASM, ACSM, AAD — professional society guidelines (wellness vertical)
- Apple HealthKit, Google Health Connect, Oura, Whoop, Garmin, Fitbit — wearables (wellness vertical)
- Sentry — error tracking
- Langfuse — LLM observability
- PostHog — product analytics (Phase 3+)

**Do not hard-code API keys anywhere.** All secrets go through Laravel Cloud's secret store in production and `.env` locally, with `.env.example` committed for onboarding. Never read secrets from user input and never write them into documentation files.

---

## Working with me — the process

1. **Read before writing.** For every task, read `docs/PROJECT_SPEC.md` (or the relevant section) and any applicable `docs/integrations/` file. For integration tasks, fetch the live official documentation.
2. **Plan before coding.** Produce a short plan — what you'll build, how you'll verify it, what questions remain — and wait for approval before writing substantive code.
3. **Ask clarifying questions.** If anything in the spec is ambiguous for the task at hand, ask. Do not guess on product behaviour, safety rules, regulatory boundaries, or vertical scope.
4. **Test and evaluate.** Unit tests for logic. Integration tests for external calls where practical. For anything touching generation quality, retrieval, or safety, add cases to the eval harness.
5. **Record decisions.** Any deviation from `docs/PROJECT_SPEC.md` or any meaningful architectural choice goes in `docs/adr/` as a dated Architectural Decision Record with context, decision, and consequences.
6. **Keep `docs/` honest.** If something changes in the product, the spec, an integration, or a decision, update the corresponding document in the same change. Docs drift silently; do not let them.

---

## What lives where in `docs/`

- `docs/PROJECT_SPEC.md` — the authoritative product and functional specification. Read this first for any substantive task.
- `docs/integrations/` — one short file per third-party service. Contains the current official documentation URL, the endpoints this project uses, auth patterns, and project-specific notes. Created and maintained as integrations are built.
- `docs/adr/` — Architectural Decision Records. Dated files recording meaningful decisions and their rationale.
- `docs/phases/` — focused task briefs for each implementation phase, referencing specific sections of the main spec.
- `docs/eval/` — the evaluation harness, golden datasets per avatar, scoring rubrics, run history. This is the quality contract — treat it as first-class.
- `docs/safety/` — red-flag triggers, crisis response templates, scope-guardrail rules per vertical. Versioned and reviewed quarterly. Changes here require domain-advisor sign-off.
- `docs/verticals/` — per-vertical configuration overviews, branding specs, tier structures, launch plans.
- `docs/compliance/` — ZDR confirmations, DPAs, regulatory positioning memos, GDPR DPIA.

---

## Quality expectations on code you write

- Idiomatic Laravel 13 / PHP 8.4. Use what the framework and the language offer; do not recreate what is already provided.
- Strong typing. Enable strict types, use typed properties, use DTOs where data crosses boundaries.
- Small, testable units. Services are injected, not instantiated. Side effects are isolated.
- Queued work for anything slow or external. The request path stays fast.
- Explicit error handling for every third-party call. Classify errors (transient, rate-limit, content, invalid-input) and handle each appropriately.
- Observability built in, not bolted on. Every LLM call, retrieval, and tool invocation is traced. Every verification event is logged with its outcome.
- Cost and latency accounted at the source. Each external call records its duration and cost before returning.
- No TODO comments without an associated ticket or ADR. Unresolved issues go in the tracker, not the code.

---

## When in doubt

- On product behaviour: re-read `docs/PROJECT_SPEC.md`. If still unclear, ask.
- On which vertical a rule applies to: check the vertical configuration; if the rule isn't clearly scoped, ask.
- On an API: fetch the live documentation, not your memory.
- On safety: escalate. Never loosen a safety rule without written approval recorded in an ADR.
- On architecture: prefer the simpler option that fits the existing stack. Introducing a new service or language requires an ADR with rationale.
- On scope: if the task has grown beyond what was asked, stop and check.