# WellnessAI — Expert Avatar Consultation Platform
## Product & Functional Specification (v4.0)

> **Purpose of this document.** This is a complete description of the product to be built: what it does, how it behaves, who uses it, how it feels, what it costs, what it must not do, and how we know it is working. It deliberately avoids code, file structure, migration details, and package names. Those decisions belong to the engineers and to Claude Code once they understand the full picture described here.
>
> **Existing stack (to be extended, not replaced).** Laravel 13 on PHP 8.4, PostgreSQL, Laravel Cloud hosting, DigitalOcean Spaces for files, GitHub for source control. A React Native mobile app will be added. Architecture decisions should favour this stack unless a capability genuinely cannot live inside it.

---

## 1. The Product in One Paragraph

A mobile-first consumer wellness app built around six visually distinct, voice-enabled expert avatars. Each avatar is a deep specialist in one domain — functional medicine, nutrition, sleep, mental wellness, fitness and longevity, skin and beauty. Users talk to them by voice or text, upload photos of food, skin, or lab reports, connect their wearables, and get personal, cited, evidence-based wellness guidance. Under the hood, one shared orchestration engine powers all six avatars by combining a curated medical knowledge base, live scientific databases, a frontier large language model, and a multi-stage quality and safety pipeline. The user sees six specialists; the system is one engine with six configurations.

---
## 1.1. Platform and verticals — the architectural shape

## The Platform Model

This product is not a single wellness app. It is an **expert-avatar platform** — a generic engine for building specialist AI consultation experiences — with wellness as the first vertical launched to the public.

The same engine already powers a pre-existing set of hotel and spa business-coach avatars in the existing codebase. Wellness is an additional vertical built on that engine, not a replacement for it. Future verticals (corporate wellness, fertility, senior care, pet health, or any other specialist-advice domain) should be addable as configuration, not as forks of the engine.

The practical consequence: anything that is specific to a domain — which avatars exist, what they know, what tools they call, what safety rules apply, what branding and copy surface in the app — is **configuration**. Anything that is the same across domains — orchestration, retrieval, verification, voice, avatar rendering, memory, billing, metering, observability — is **engine**. If a feature being built requires the engine to know which vertical it is running for, that is a warning sign that the feature has been placed on the wrong side of the line.

## What the Engine Owns

The engine is domain-agnostic. It provides:

- The orchestration state machine that takes an incoming message, routes it, retrieves evidence, generates a response, verifies it, and streams it back.
- The retrieval pipeline: hybrid search, reranking, tool-call execution, evidence-grade weighting.
- The verification pipeline: claim extraction, grounding check, cross-model critic, citation validation, safety and scope classification, revision loop.
- The memory layer: conversation history, rolling summaries, semantic memory.
- The voice and avatar rendering infrastructure: session token issuance, streaming integration points.
- The billing and metering infrastructure: subscription entitlements, per-user quotas, per-session cost caps, usage ledger.
- The observability layer: tracing, cost accounting, latency monitoring, logging.
- The administrative capabilities: prompt versioning, knowledge ingestion, reviewer queue, evaluation harness.

None of these know or care whether they are serving a wellness user or a hotel operator.

## What a Vertical Owns

A vertical is a configuration package. Each vertical defines:

- Which avatars exist, with their personas, names, faces, voices, and visual styles.
- Which knowledge bases each avatar uses.
- Which tools each avatar has access to.
- Which handoff relationships exist between avatars.
- Which safety rules apply — red-flag triggers, scope guardrails, crisis response templates, regulatory positioning language.
- Which disclaimers surface at which moments.
- Which branding — colours, typography, name, logo, App Store listing — the mobile app presents.
- Which subscription tier structure applies, and at what price points.
- Which languages are supported.

Verticals are **data**, not **code**. Adding a new vertical should require no changes to the engine.

## The Existing Hotel Vertical

The existing codebase contains a set of hotel and spa business-coach avatars — marketing, accounting, spa-therapist, and similar specialist personas aimed at hotel operators. These continue to operate as a vertical named `hotel` (or a similar slug). The existing Vite SPA at `/spa` remains its primary delivery surface. No existing hotel functionality is to regress as the wellness vertical is built.

The hotel vertical's safety rules, tool access, and knowledge bases are distinct from the wellness vertical's. For example, hotel avatars do not need access to medical literature APIs, and wellness avatars do not need access to booking-platform integrations. The tool-enablement matrix is scoped per vertical and then per avatar within that vertical.

## The Wellness Vertical

The wellness vertical is the subject of the main body of this specification. It includes the six avatars described in Section 4: Dr. Integra, Nora, Luna, Zen, Axel, and Aura. It is delivered primarily through a new React Native mobile app (branded WellnessAI, published to both App Stores) and secondarily through the web SPA as a companion.

The wellness vertical carries the strictest safety profile of any vertical in the platform. Its regulatory positioning is wellness education, not medical advice — this positioning applies to the wellness vertical's configuration and user-facing surfaces, and does not automatically apply to other verticals.

## Mobile App Strategy

The mobile app codebase is shared across verticals. A build-time flag (and associated configuration bundle) determines which vertical a given build represents — its branding, its avatars, its tier structure, its App Store metadata. This produces separate App Store listings for each vertical while maintaining a single codebase.

At launch, only the wellness build is published publicly. A hotel-facing mobile build may follow later if product-market fit justifies it; until then, hotel users continue to use the web SPA.

## Database and Data Model Implications

The database tables that represent avatars, knowledge, tools, safety rules, and similar domain-configurable entities carry a vertical scope. Users are scoped to one or more verticals depending on business model. Conversations, messages, sessions, and usage are scoped to a vertical for analytics and access control.

Cross-cutting tables (observability, verification events, cost accounting, audit logs) are vertical-tagged but otherwise shared — the engine does not branch its logic on vertical identity.

## Safety Rules Are Per-Vertical

The hard rules in the main specification (no diagnosis, no prescription, red-flag triggers, etc.) are the hard rules of the **wellness** vertical. They are non-negotiable within that vertical. Other verticals carry their own hard rules appropriate to their domain — a hotel-marketing avatar might have rules against giving tax or legal advice, for example. Each vertical's safety rules live in its configuration, with per-vertical sign-off by the appropriate domain advisor.

The engine's role is to enforce whatever rules the active vertical specifies, not to encode any rules of its own.

## Adding a New Vertical in Future

A new vertical is added by: defining its avatars and their configurations, curating and ingesting its knowledge bases, configuring its tool access, authoring its safety rules and red-flag triggers with a qualified domain advisor, authoring its branding and App Store metadata, setting its subscription structure, and producing its mobile build. No engine code changes are required for the happy path. If a new vertical surfaces a requirement the engine cannot currently satisfy — a new input modality, a new tool category, a genuinely novel orchestration pattern — that requirement becomes an engine-level change, reviewed as an Architectural Decision Record, so that it benefits all verticals rather than being hidden inside one.

## What This Means for the Current Refactor

The current codebase is not a greenfield wellness project. It is an existing expert-avatar application serving the hotel vertical, being extended in three ways: (1) the engine is being matured to meet the quality, safety, and scale standards described in this specification, (2) a new wellness vertical is being added with its six avatars and mobile app, and (3) the database model is being extended to cleanly separate engine from vertical configuration.

Phase 0 of the implementation plan is therefore a refactoring phase, not a greenfield phase: introduce the vertical concept into the data model, extend the core tables to support the full specification's requirements (verification events, citations, token usage, subscription entitlements, and so on), ensure the existing hotel flows continue working, and stand up the foundational infrastructure (pgvector, evaluation harness, telemetry, mobile shell) that subsequent phases depend on. The exit criterion for Phase 0 is that the existing hotel vertical is unaffected and the foundations required for Phase 1 are in place and proven.

---


## 2. Who This Is For

**Primary user.** Health-conscious adults aged 25–55 who already spend on wellness — supplements, fitness apps, wearables, spa services — and want more personal, more trustworthy guidance than generic ChatGPT offers but are not looking for a clinical diagnosis.

**Trigger moments to engage the app.**
- They get a new set of blood results and want to understand them.
- They're not sleeping well and the generic advice isn't working.
- They want a real nutrition plan, not a templated one.
- They saw something on their skin and want informed reassurance or escalation.
- They want a coach that actually knows them and remembers.

**Explicitly not for.**
- People under 18.
- People seeking a diagnosis, prescription, or replacement for licensed medical care.
- Clinical or enterprise healthcare deployments in v1.

---

## 3. Core Product Principles

1. **Specialists, not a generalist.** Every consultation feels like talking to someone who lives and breathes that one topic.
2. **Evidence visible.** Every factual claim shows its source; users can tap any citation and see where it came from.
3. **Safety before helpfulness.** When something serious appears, the avatar stops and refers the user to appropriate care. Always. With no workaround.
4. **Voice-first on mobile.** The best wellness conversations happen hands-free — walking, cooking, winding down before bed.
5. **Personal and persistent.** The avatars remember the user's goals, conditions, preferences, and history. Every conversation builds on the last.
6. **Transparent economics.** Users always know how many messages and how much quota remain. No surprise paywalls, no dark patterns.

---

## 4. The Six Avatars

Each avatar has a name, a domain, a visual identity, a voice, a personality, a curated knowledge base, and a defined scope. Each is a specialist whose expertise is deep and whose boundaries are clear.

### 4.1 Dr. Integra — Functional & Preventive Medicine
**What she does:** interprets blood panels, hormone panels, and micronutrient tests; explains biomarkers in accessible language; suggests root-cause-oriented wellness strategies; tracks biomarker trends over time.
**Personality:** precise, calm, explanatory. A scientist who respects her audience.
**Canonical moment:** a user uploads a PDF of their lab results; within seconds, Integra walks them through each panel, flags what's optimal versus merely "normal," and suggests three specific lifestyle experiments backed by citations.
**Hard limits:** no diagnosis, no prescription, no dosing of prescription medication. Hands off to emergency resources for red-flag symptoms.

### 4.2 Nora — Nutrition & Gut Health
**What she does:** personalised nutrition guidance, microbiome and gut-health protocols, food sensitivity navigation, meal planning, label decoding, ingredient analysis.
**Personality:** warm, energetic, pragmatic. A friend who happens to have read every nutrition study.
**Canonical moment:** a user photographs their lunch. Nora identifies the dish, estimates macros and micronutrients, scores its anti-inflammatory profile, and suggests one swap that would improve it — all in under ten seconds.
**Hard limits:** no specific caloric prescriptions for users with signs of disordered eating; in those cases, redirects to Zen and to professional resources.

### 4.3 Luna — Sleep & Recovery
**What she does:** sleep architecture, circadian rhythm, insomnia protocols, recovery tracking, light and temperature optimisation, HRV interpretation.
**Personality:** quiet, unhurried, soothing. Optional whisper-mode voice for bedtime use.
**Canonical moment:** at 11pm, a user asks why they've been waking at 3am. Luna pulls three nights of Oura data, correlates with HRV and resting heart rate, and offers a specific hypothesis with two small experiments to test over the next week.
**Hard limits:** suspected sleep apnoea, parasomnia, or narcolepsy triggers a referral to a sleep physician.

### 4.4 Zen — Mindfulness & Mental Wellness
**What she does:** stress physiology, nervous system regulation, breathwork, meditation, anxiety protocols. Can actually run guided sessions with voice pacing — not just describe them.
**Personality:** grounded, unhurried, present. The voice of a seasoned teacher.
**Canonical moment:** a user says they're anxious before a meeting. Within thirty seconds, Zen is leading a 3-minute physiological sigh protocol with pacing cues, then checks in at the end.
**Hard limits:** suicidal ideation, self-harm intent, psychosis, or severe depression triggers an immediate crisis protocol — no wellness advice is given, only direct, compassionate referral to professional resources.

### 4.5 Axel — Longevity, Fitness & Movement
**What he does:** strength programming, cardiovascular fitness, mobility, movement quality, progressive overload, longevity protocols, injury prevention.
**Personality:** direct, motivating, specific. A trainer who actually knows exercise science.
**Canonical moment:** a user films themselves doing a squat. Axel gives three specific cues — knee tracking, torso angle, depth — and adjusts their programme for the week accordingly.
**Hard limits:** acute injury, cardiac symptoms during exercise, or signs of overtraining syndrome trigger medical referral.

### 4.6 Aura — Skin, Beauty & Aesthetic Health
**What she does:** skincare routines grounded in dermatology, ingredient analysis, aesthetic procedure education, product recommendations, in-spa treatment guidance.
**Personality:** elegant, knowledgeable, a little poetic. Luxury brand aesthetic.
**Canonical moment:** a user takes a morning selfie. Aura assesses skin type, identifies concerns, and suggests a routine — while also clearly stating what she can and cannot tell from an image.
**Hard limits:** any mole or lesion matching melanoma screening criteria (asymmetry, border, colour, diameter, evolution) triggers an urgent dermatologist-referral response with no cosmetic advice given.

### 4.7 How the Avatars Work Together

When a user is talking to one avatar and raises a topic outside that avatar's domain, the current avatar acknowledges it and offers to bring in the right specialist. Handoffs preserve full context. The user can also explicitly switch avatars at any moment. The system is aware of which avatars have already touched which topics for a given user and threads continuity across the conversation.

---



## 5. Under the Hood — What the System Actually Does

The user sees six specialists. The engineering reality is one orchestration engine that, for each consultation, performs the following work invisibly.

### 5.1 Input understanding
The user's message arrives as text, voice, an image, a video clip, a document, or a wearable data window. The system normalises all of these into a structured request and attaches the user's relevant profile, goals, and recent conversation memory.

### 5.2 Safety first
Before any generation, the system runs a safety check. If the message contains crisis indicators, emergency symptoms, or other pre-defined red flags, the engine routes immediately to an emergency protocol response. No further generation happens. This check is deterministic and cannot be overridden by clever prompting.

### 5.3 Avatar routing
The engine confirms which avatar should answer. If the current avatar is the right one, it continues. If the message is clearly in another avatar's domain, it either asks the user if they want to switch or silently brings in knowledge from the other avatar while keeping the current persona in the foreground.

### 5.4 Knowledge retrieval
The system searches the avatar's curated knowledge base (the core corpus of textbooks, guidelines, and peer-reviewed references) and, where relevant, live external databases (scientific literature, food data, drug information, cosmetic ingredient registries). It returns the most relevant excerpts, ranked by relevance and evidence quality.

### 5.5 Response generation
The primary language model (Claude Opus 4.7 at launch) produces a response in the avatar's voice, grounded in the retrieved material, with inline citations. Responses aim to be conversational, actionable, and honest about uncertainty.

### 5.6 Verification
Before the user sees the response, it is checked. Each factual claim is tested against the retrieved evidence — is it actually supported? Every citation is validated — is the source real, and does it say what the response claims it says? The tone and scope are reviewed — does it stay within the avatar's domain, avoid diagnosis and prescription language, handle any sensitive topics appropriately? If any check fails, the response is revised (up to twice). If it still fails, a softened or referral-only response is delivered instead.

**From v1.1 onwards**, the verification stage uses a different model family (GPT-5.4) as the critic. The different-family critic catches the kinds of errors a same-model check would miss. This is the single biggest quality lever after launch.

### 5.7 Delivery
The verified response is streamed back to the user. Voice is generated by a streaming text-to-speech service while the avatar's face animates with real-time lip-sync. Any structured outputs — a meal plan, a protocol, a supplement list, follow-up prompts — appear alongside the conversation in side panels the user can expand, save, or export.

### 5.8 Memory and learning
The conversation is summarised and saved. The user's profile is updated with anything they've revealed. Future conversations with any avatar can draw on this memory so the experience compounds over time.

### 5.9 Observation and improvement
Every response, every retrieval, every verification step is logged for quality monitoring. Low-scoring responses are queued for expert review. An automated evaluation harness runs nightly against a library of known questions to catch any quality regressions before users notice them.

---

## 6. Mobile App Experience

### 6.1 First-time user journey (target: under three minutes to first consultation)

1. **Welcome.** A short looping video showing the six avatars speaking.
2. **Goals.** The user selects what they want to improve — weight, sleep, stress, skin, energy, fitness, longevity, biomarkers, nutrition. Multi-select. This drives which avatar surfaces first.
3. **Profile.** Age, sex, height, weight, activity level, dietary pattern and allergies, any medical conditions they wish to share, any medications, which wearables they use.
4. **Consent and jurisdiction.** An 18+ age gate, a clear statement that this is wellness education and not medical advice, GDPR or CCPA consent depending on detected region.
5. **Meet the specialists.** A carousel introducing each avatar by name with a short voice preview.
6. **Customise.** For each avatar, the user picks an appearance (from three to five curated looks) and a voice (from three options). This is their personal set of specialists. Customisation is changeable later.
7. **Connect wearables (optional, skippable).** OAuth flows for Apple Health, Google Fit, Oura, Whoop, Garmin, Fitbit.
8. **Start free trial.** Five days, full access, no credit card required.
9. **First conversation.** The app suggests the avatar best matched to the user's top goal and invites them in.

### 6.2 Home screen

The home screen feels personal. At the top, a greeting and a daily focus — something specific, based on the user's data, generated fresh each morning: "Luna suggests winding down 30 minutes earlier tonight based on your last three nights." Below, the six avatars arranged by relevance to this user's goals, each with a small hint at what they're ready to discuss. A row of quick actions — ask any specialist, log a meal, log sleep, scan a product, upload a lab. A progress strip showing active protocols, streaks, and wearable highlights. On free and trial tiers, a clear but unobtrusive meter showing remaining messages for today.

### 6.3 The conversation screen — the heart of the app

The avatar fills the screen, fully animated and lip-synced while speaking. A large central button invites voice input; the user holds to speak, or toggles hands-free mode. A small text field at the bottom offers typed input for quiet environments. A camera button attaches a photo — food, skin, a product label, a lab report, or a short video for form-check. While the avatar is speaking, the user can interrupt — tap to pause, ask a follow-up. Inline citations appear as small numbered chips; tapping one opens a sheet showing the source title, a short excerpt, and a link to the full reference.

A button in the corner calls in another specialist. When a handoff happens, the new avatar's face replaces the current one with a smooth transition and a natural verbal bridge — "Let me bring in Luna for this one."

### 6.4 Protocol screen

After each consultation, the structured take-aways are filed here: today's action items, any supplements discussed, protocols to try, follow-up questions to explore. The user can set reminders, tick items off as done, and track how their experiments went. Protocols can be exported as a PDF the user could share with their actual doctor.

### 6.5 Library screen

Every past conversation, searchable. Every uploaded document, every food photo, every skin selfie (if kept), every workout video. Every insight the avatars have generated. The library becomes the user's evolving wellness record.

### 6.6 Settings

Subscription and token usage. Avatar customisation. Wearable connections. Privacy controls including full data export and right-to-erasure. Notification preferences. Accessibility options.

### 6.7 Exhibition kiosk mode

The same mobile app in kiosk mode: no onboarding, anonymous session, sixty-second timer per visitor, a PDF take-away generated at the end, and a QR code the visitor can scan to download the app and continue the conversation on their own phone. Auto-resets between visitors.

### 6.8 Design language

Warm, premium, calm. The visual identity is centred on the avatars themselves — their faces are the product. Everything else recedes. Typography is generous, hierarchy is clear, motion is smooth but understated. Dark mode is a first-class citizen, not a retrofit. Each avatar has a subtle colour accent used in their screens, citations, and protocols — continuity without clutter. Voice interaction is visibly prioritised in every interface decision.

### 6.9 Behaviours that matter

The app is voice-first but never voice-only — text is always one tap away. The app is network-aware — on weak connections, avatar video gracefully degrades to a still portrait with voice only, and voice degrades to text only if needed. The app is battery-aware — no background language-model calls, the avatar stream suspends when the app goes to the background. The app is data-aware — video uploads warn on cellular. The app is accessible from day one — full VoiceOver and TalkBack support, dynamic type, contrast, captions for avatar speech.

---

## 7. Knowledge and Evidence

### 7.1 Per-avatar curated corpus

Each avatar has its own knowledge base of between five hundred and two thousand carefully curated documents — licensed textbooks, society guidelines, seminal peer-reviewed papers, and vetted clinical references. This is where the specialist feel genuinely comes from. Quality beats quantity: a tightly curated sleep library produces better answers than a dump of every paper mentioning sleep.

Every document is tagged with its evidence grade — systematic review, randomised trial, observational study, expert opinion, or guideline. The avatar is instructed to prefer high-grade evidence and to explicitly flag when only low-grade evidence is available for a claim.

### 7.2 Live scientific and reference databases

On top of the curated corpus, avatars can query live sources at conversation time: PubMed and Europe PMC for biomedical literature, Cochrane for systematic reviews, Semantic Scholar for broader academic context, USDA FoodData Central for nutrition, Open Food Facts for product barcode lookups, DrugBank and RxNorm for medication information, LOINC for lab code normalisation, CosIng and INCI for cosmetic ingredient safety, and the practice guidelines of relevant professional societies (AASM for sleep, ACSM for exercise, AAD for dermatology).

### 7.3 Ongoing maintenance

Live-source caches refresh nightly. The corpus is reviewed monthly by domain experts, with the top hundred most-retrieved chunks per avatar examined for quality. Society guidelines are tracked for updates and refreshed when published. Every change is versioned; every version is tested against the evaluation harness before going live.

### 7.4 Licensing discipline

Every document in the corpus has tracked licence status. A parallel fallback corpus of purely open-access material exists in case of any licensing dispute, so the product can continue to operate with degraded but legal coverage.

---

## 8. Quality, Verification, and Safety

### 8.1 The quality bar

Zero tolerance for fabricated citations. At least ninety percent of factual claims must be grounded in retrieved evidence. No diagnosis, no prescription, no dose recommendations for prescription medications. Safety rules cannot be overridden by any form of user input, however clever.

### 8.2 Verification in practice

Every response passes through a verification pipeline before the user sees it. The claims are extracted. Each claim is tested for grounding against the actual retrieved sources. The citations are validated — every link and identifier must resolve to a real source. A critic check reviews the full response against a structured checklist covering accuracy, completeness, scope adherence, safety handling, and persona. A safety classifier checks for drift into diagnosis or prescription language. If any stage fails, the system revises and re-checks, up to twice. If it still cannot produce a safe, grounded response, it delivers a softened answer or a professional-referral response instead of anything risky.

In v1.0, the critic is the same model family as the primary. In v1.1, the critic switches to a different family (GPT-5.4 checking Claude's output, and vice versa). The quality jump is measurable and the cost is bearable. In v1.2, Gemini is added for vision-specific tasks.

### 8.3 The red-flag system

A deterministic layer sitting above all generation. Chest pain, acute neurological symptoms, suicidal ideation, severe allergic reaction, pregnancy emergencies, melanoma screening criteria, severe drug interactions, eating disorder indicators — each triggers a pre-authored emergency response template, surfaces appropriate professional resources, pauses the conversation, and logs the event. No creative response is generated in these cases. The list is versioned, reviewed quarterly, and owned by the medical advisory team.

### 8.4 The evaluation harness

Built first, before anything else in the product. For each avatar, a golden dataset of two hundred to five hundred expert-authored question-and-answer pairs, each specifying the ideal answer, required citations, and any red flags that must fire. Every night, the harness runs the full pipeline against every dataset and scores each response on accuracy, safety, citation validity, persona adherence, and handoff correctness. Any regression against the previous live version blocks deployment of the new version. This is how we know the product is actually getting better over time rather than drifting.

### 8.5 The expert-reviewer loop

One medical or domain advisor per two avatars, on retainer. Low-confidence responses are automatically flagged and queued for their review. Their feedback feeds back into prompt improvements and knowledge-base gap analysis. A quarterly advisory council reviews aggregate metrics and approves knowledge-base updates.

### 8.6 Regulatory positioning

The product is positioned as wellness education and lifestyle guidance. It does not provide medical diagnosis, treatment, or prescription. This positioning must be respected in every user-facing surface — app copy, marketing, App Store listing, website, the avatars' own speech. Drift into clinical language would reclassify the product as a regulated medical device in the EU (MDR Class IIa or higher) and trigger an eighteen-month-plus delay for CE marking. This is a strict boundary, not a preference.

Data protection is GDPR-compliant by default, with CCPA coverage for US users. Health data is encrypted with tenant-scoped keys. EU users' data stays in the EU. Language model providers are used only under zero-data-retention agreements so user content is never used to train third-party models. Right to erasure cascades across all stores, including vector embeddings and provider logs where opt-out is available.

---

## 9. Business Model and Monetization

### 9.1 Launch strategy

The app launches publicly, free to download, on both Android and iOS. Android launches first — faster review, cheaper user acquisition, better iteration tempo. iOS follows a week later once any Android-surfaced issues are resolved. For the first two weeks after launch, subscriptions are disabled while engagement and retention data is collected at scale. This protects the subscription conversion rate from being measured against a still-evolving product. In week three, subscriptions enable.

### 9.2 Trial

Every new user gets a five-day free trial with full Pro-tier access. No credit card is required upfront — this single choice materially reduces install-to-engagement drop-off for consumer apps. On day three, a gentle in-app prompt asks how it's going and offers an early-bird upgrade. On day five, a final reminder before the trial ends. After the trial, the account drops to the free tier. All history is preserved; active protocols pause after a week if not on a paid plan.

### 9.3 Tiers

**Free (post-trial).** One avatar of the user's choice. Five messages per day. Text only. No uploads, no wearables, no voice, seven days of conversation memory. Enough to stay engaged and see ongoing value, not enough to replace paid usage.

**Basic — around ten dollars per month.** All six avatars. Text and voice. Basic uploads (food photos). Thirty messages per day. Thirty days of memory.

**Pro — around twenty dollars per month.** Everything in Basic plus lab report OCR and interpretation, skin analysis, wearable integrations, unlimited memory, PDF exports, and one hundred messages per day.

**Ultimate — around forty dollars per month.** Everything in Pro plus video form-check, priority response times, the highest-tier avatar rendering, and early access to new avatars. Fair-use limit of five hundred messages per day.

**Annual plans** at roughly thirty percent off the monthly rate. Annual is where lifetime value lives in this category — monthly-only pricing leaks users aggressively at month two and three.

Exact prices are set per market; subscription storefronts use stepped pricing tiers rather than arbitrary amounts.

### 9.4 Metering model

Two numbers are tracked: messages sent today and tokens consumed this month. Messages are the user-facing primary constraint — simple, understandable, honest. Tokens are a secondary abuse guard. The user always sees their remaining quota in the app; approaching a limit shows a polite nudge at eighty percent, a clear choice at one hundred — upgrade or wait for tomorrow. The cost of the verification pipeline is borne by the business, not charged to the user — the user pays for the primary response, verification is our quality investment.

Each individual session also has a hard server-side cost cap so that a single runaway conversation cannot burn through a user's value or our margin.

### 9.5 Unit economics

Per consultation at launch, total cost is roughly thirty to fifty cents, rising slightly once the cross-model critic is added. Basic tier is thin-margin at the unit level; Pro is break-even-to-healthy depending on usage intensity; Ultimate is the profitable tier. The strategy is to use Basic as a conversion ladder, Pro as the sweet spot, and Ultimate as the monetisation anchor, with annual plans driving lifetime value across all three.

### 9.6 Subscriptions infrastructure

A third-party subscription platform handles the complexity of mobile in-app purchases — receipt validation, entitlement management, trial tracking, cross-platform identity, cancellations, refunds, proration. Building this from scratch is a multi-month trap; using a purpose-built provider is a non-negotiable decision.

---

## 10. The Avatars as Brand

The six avatars are not just a technical pattern. They are the brand, the marketing, the App Store presence, and the moat.

Each avatar has a distinctive face, voice, colour, and speech style. Each has canonical scenarios where they shine — these become the basis for marketing assets, App Store screenshots, exhibition demos, and influencer partnerships. Each has a potential content extension — a blog, a TikTok, a newsletter — in their own voice, building a following before they ever meet a user.

When a user tells a friend about the app, they don't say "I use a wellness AI." They say "Luna told me to move my last coffee earlier." That difference is the product.

---

## 11. Operations and Ongoing Work

### 11.1 Administrative capabilities (available from day one)

A staff-facing console supports the following work without engineering intervention:

- Configure avatars: persona prompt versions with diff view and A/B testing, voice and visual preset libraries, enabled tools, safety rules, red-flag triggers, handoff rules.
- Manage knowledge: upload new sources, preview how they chunk, approve or reject, trigger re-indexing, retire outdated sources, track licence status and versions.
- Run and review evaluations: launch eval runs against any prompt version, compare scores, gate promotion of new versions on passing the harness.
- Review flagged responses: a queue of low-confidence or user-downvoted responses; expert reviewers leave structured feedback that feeds back into improvements.
- Monitor costs: spend per provider, per avatar, per tier, per user cohort.
- Audit safety: the log of every red-flag trigger, every emergency template delivered, every crisis escalation.
- Manage users: view any user's profile, usage, subscription state, and — on documented request — export or erase their data.

### 11.2 Observability

Every language-model call, every retrieval, every tool invocation, every verification stage is traced with parent-child relationships so any conversation can be reconstructed and debugged. Latency percentiles are monitored per stage so regressions are visible before users notice. Cost per response is monitored in real time so the business understands its unit economics daily.

### 11.3 Continuous improvement loop

Weekly: review the top flagged responses and user-downvoted sessions; adjust prompts and surface knowledge-base gaps.
Monthly: review the top one hundred most-retrieved chunks per avatar; prune or improve.
Quarterly: medical advisory council reviews aggregate metrics, approves corpus refreshes, and re-validates red-flag rules against evolving standards.

---

## 12. Non-Functional Requirements

### 12.1 Performance

First audible response in three seconds or under. Full verified response in eight seconds or under. Avatar animation smooth at thirty frames per second. Streaming at every stage of the pipeline — no user-visible waits. Graceful degradation on weak networks, not freezes.

### 12.2 Reliability

All critical provider integrations have documented fallback behaviour. If the avatar provider is down, the conversation continues as voice-only with a still portrait. If text-to-speech is down, the response appears as text. If a live scientific API is unreachable, the avatar works from its curated corpus and notes the limitation. If the primary language model is down, the secondary provider takes over automatically.

### 12.3 Security and privacy

Defence-in-depth: encryption at rest and in transit, short-lived tokens for third-party services never exposed to the client, tenant-scoped keys for health data, strict separation of user-supplied content (always treated as untrusted) from system instructions. Prompt-injection defences are tested regularly via a red-team harness. No user health content is ever used to train any third-party model.

### 12.4 Accessibility

Full screen-reader support, dynamic type, high-contrast mode, captions for avatar speech, voice-only and text-only operation modes for users who cannot use the full experience. WCAG 2.2 AA as the target.

### 12.5 Internationalisation

English at launch. Infrastructure is in place for Spanish, German, and French to be added within three to six months post-launch. Each new language requires localised avatar voices, localised evidence-base coverage review, and localised safety templates — this is not just a UI translation.

---

## 13. What We Build and When

The work divides naturally into phases. Each phase has clear exit criteria that must be met before the next begins.

### Phase 0 — Foundations
Stand up the extended infrastructure on top of the existing Laravel stack, get basic telemetry flowing, get the mobile app shell talking to the backend. Exit when a mobile "hello" round-trips and all services are reachable.

### Phase 1 — One Avatar, Production Quality
Build the evaluation harness first. Then deliver Nora end-to-end: knowledge ingestion, retrieval, generation, citation validation, safety checks, memory, mobile chat screen with streaming text. No voice, no avatar rendering yet. Exit when Nora scores eighty-five percent or higher on her golden dataset, zero hallucinated citations, all red-flag tests caught.

### Phase 2 — Voice, Avatar, and All Six Specialists
Add voice in and out, avatar rendering, and clone Nora's configuration to the other five avatars. This phase proves the one-engine-six-configurations architecture — adding avatars two through six should require no new engine code. Cross-avatar handoffs work. Exit when every avatar scores at or above eighty-five percent on its golden dataset and first-audio latency meets three seconds on real mobile networks.

### Phase 3 — Onboarding, Memory, and the Free Experience
Full first-run onboarding, goal selection, avatar customisation, long-term memory, the home, library, and settings screens. Free-tier metering is enforced.

### Phase 4 — Monetization
Trial mechanics, subscription integration, tier enforcement, paywall screens, usage meter, cancellation and restore flows. Exit when an end-to-end purchase, trial, cancellation, and restore all work cleanly on both platforms.

### Phase 5 — Multimodal and Wearables
Lab report interpretation, food photo analysis, skin selfie assessment, movement photo or video analysis, wearable OAuth and daily sync, protocol screen.

### Phase 6 — The Quality Upgrade (v1.1)
Add the cross-model critic. A/B test it on the evaluation harness. Promote it only if the lift is measurable.

### Phase 7 — Polish, Beta, Launch
Accessibility, localisation infrastructure, closed beta with a couple of hundred users, bug bash, Android launch, iOS launch, subscription enablement two weeks later.

### After launch
Exhibition kiosk build (reuses almost everything), web companion app, additional avatars, deeper integrations, continuous prompt and corpus iteration driven by the evaluation harness and reviewer queue.

---

## 14. What Success Looks Like

Twelve months after launch, success means: users describe the avatars by name to their friends; at least one canonical scenario per avatar regularly appears in user-generated content; the evaluation harness shows every avatar above ninety percent accuracy; zero safety incidents of meaningful severity; conversion from trial to paid above the consumer-app average for wellness; retention at month three above fifty percent on annual plans; a waiting list for new avatars the team plans to add next.

Failure modes to avoid actively: avatars that sound generic despite different faces, citations that drift into fabrication, safety rules that are silently bypassed by creative users, paywall placement that feels manipulative, accessibility as an afterthought, and mobile performance that is acceptable on the team's flagship phones but painful on a mid-range Android in a weak signal area.

---

## 15. What This Document Is Not

This document is not a data model, not a file structure, not an API specification, not a package manifest, and not a sprint plan. Those artefacts will be produced by the engineering team and by Claude Code as they implement against this brief. Their job is to make the product described above real, using the existing stack where it fits and extending it where it must. This document's job is to make sure everyone — engineers, designers, advisors, and Claude Code — shares the same mental model of what we are building and why.

---

*End of v4.0 specification. This document is the authoritative description of the product and supersedes all prior versions.*
