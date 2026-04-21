# Verification Pipeline (Claim Extraction, Grounding, Citation Validation) — Design Spec

**Date:** 2026-04-21
**Phase:** 1, sub-project #3 of 6
**Status:** Draft — awaiting implementation

## Goal

Implement a safety-critical verification pipeline that ensures every response from Nora (and future avatars) is grounded in retrieved evidence, carries valid citations, avoids diagnosis/prescription drift, and stays within scope. No fabricated sources. No hallucinated PMIDs or DOIs. Every factual claim must resolve to an actual, retrievable source.

## Non-Goals

- Cross-model critic (GPT-5.4 checking Claude output) — Phase 1.1 upgrade, v1.1
- Vision-based verification for food/skin photos — Phase 2
- Advanced claim decomposition (multi-hop reasoning) — Phase 2+
- LLM-as-judge scoring on retrieval quality — Phase 2+
- Continuous learning from expert review feedback — Phase 2 (initial loop is manual)

## Scope — What This Sub-Project Produces

1. **Claim Extraction Service** (`app/Services/Verification/ClaimExtractionService.php`)
   - Structured LLM call extracts factual claims from response text
   - Returns array of `Claim` objects (text, requires_citation, inferred_source_category)

2. **Grounding Service** (`app/Services/Verification/GroundingService.php`)
   - Semantic similarity search: matches each claim against retrieved context chunks
   - Uses EmbeddingService from Phase 1 sub-project #2
   - Returns `GroundingResult`: (is_grounded, matched_chunk, similarity_score, supporting_evidence)

3. **Citation Validators (per-source)**
   - `app/Services/Verification/CitationValidators/UsdaCitationValidator.php` — FDC ID lookup
   - `app/Services/Verification/CitationValidators/PubMedCitationValidator.php` — PMID E-utilities lookup
   - `app/Services/Verification/CitationValidators/OpenFoodFactsCitationValidator.php` — barcode/URL lookup
   - `app/Services/Verification/CitationValidators/GenericCitationValidator.php` — HTTPS URL resolution (with caching)

4. **Safety Classifier** (`app/Services/Verification/SafetyClassifier.php`)
   - Deterministic pattern matching for hard rules (diagnosis, prescription, dosing, emergency keywords)
   - Returns array of `SafetyFlag` objects (severity, matched_pattern, suggested_action)

5. **Structured Review Service** (`app/Services/Verification/StructuredReviewService.php`)
   - LLM-based final review checklist: accuracy, completeness, scope adherence, safety handling, persona
   - Returns `StructuredReviewResult`: (passed, issues_found, revision_suggestions)

6. **Main Verification Service** (`app/Services/Verification/VerificationService.php`)
   - Orchestrates all stages: extraction → grounding → citation validation → safety → review
   - Implements revision loop (up to 2 attempts)
   - Returns `VerificationResult`: (is_verified, failures[], revision_count, safety_flags[])

7. **Verification Event Logging**
   - `verification_events` table: audit trail of every verification run
   - Tracks response text, verification outcome, failures, safety flags, latency, timestamps

8. **Citation Validation Cache**
   - Redis-backed cache for PMID/FDC ID/barcode lookups (24-hour TTL)
   - Prevents redundant API calls, speeds up response

9. **Feature Tests**
   - Full pipeline tests on Nora's golden dataset (17 cases)
   - Hallucination detection (injected fake citations should be caught)
   - Safe response pass-through (no false positives on legitimate wellness claims)
   - Revision loop behavior (second attempt uses failure feedback)

## Exit Criteria For This Sub-Project

- All five core services (extraction, grounding, citation validation, safety, structured review) fully implement their contracts
- Citation validators for USDA, PubMed, Open Food Facts successfully resolve real sources
- Verification event logging captures 100% of responses with full audit trail
- Pipeline processes Nora's golden dataset with 0 false positives on safe responses
- Injected hallucinations (fake PMIDs, fake URLs, diagnosis drift) are caught in ≥95% of cases
- Revision loop successfully re-verifies corrected responses (second pass succeeds where first failed)
- Safety classifier triggers correctly on hard-rule patterns (drug dosing, diagnosis language, emergency keywords)
- Latency added to generation pipeline is ≤2 seconds median (acceptable for health app)
- No regressions in hotel vertical (existing agents without verification continue to work)
- Full backend test suite passes (all existing tests green)

## Architecture

### Response Verification Flow

```
LLM Generation (via LlmClient)
    ↓ response_text
RetrievalService.retrieve($prompt, $avatar)
    ↓ RetrievedContext { chunks[], latency_ms, is_high_risk }
VerificationService.verify($response_text, $retrieved_context, $agent)
    ├─→ [Stage 1] ClaimExtractionService.extract($response_text)
    │   └─→ Claim[] { text, requires_citation, inferred_source_category }
    │
    ├─→ [Stage 2] GroundingService.ground_all_claims($claims, $retrieved_context)
    │   └─→ Claim[] with attached GroundingResult { is_grounded, matched_chunk, similarity_score }
    │
    ├─→ [Stage 3] CitationValidationService.validate_all_citations($claims)
    │   └─→ Claim[] with attached CitationValidationResult { is_valid, validation_detail, resolved_source }
    │
    ├─→ [Stage 4] SafetyClassifier.classify($response_text)
    │   └─→ SafetyFlag[] { severity, pattern, suggestion }
    │
    ├─→ [Stage 5] StructuredReviewService.review($response_text, $failures_so_far)
    │   └─→ StructuredReviewResult { passed, issues[], suggestions }
    │
    └─→ VerificationResult {
        is_verified: bool,
        failures: VerificationFailure[],
        safety_flags: SafetyFlag[],
        revision_count: int,
        latency_ms: int
    }

[If is_verified = true] → Stream response to user
[If is_verified = false AND revision_count < 2] → LLM.revise($response_text, $failures) → loop back to Stage 1
[If is_verified = false AND revision_count >= 2] → Return fallback response (softened or professional referral)
```

### Key Data Structures

#### Claim (DTO)

```php
final class Claim
{
    public function __construct(
        public readonly string $text,
        public readonly bool $requires_citation,
        public readonly string $inferred_source_category, // 'nutrition'|'research'|'guideline'|'database'
        public readonly ?GroundingResult $grounding = null,
        public readonly ?CitationValidationResult $citation = null,
    ) {}
}
```

#### GroundingResult (DTO)

```php
final class GroundingResult
{
    public function __construct(
        public readonly bool $is_grounded,
        public readonly ?KnowledgeChunk $matched_chunk = null,
        public readonly float $similarity_score = 0.0, // 0–1
        public readonly string $supporting_evidence = '', // excerpt from chunk
    ) {}
}
```

#### CitationValidationResult (DTO)

```php
final class CitationValidationResult
{
    public function __construct(
        public readonly bool $is_valid,
        public readonly string $validation_detail, // 'PMID:12345 resolved', 'FDC ID not found', 'URL returned 404'
        public readonly ?string $resolved_source_url = null,
        public readonly ?string $source_type = null, // 'pubmed'|'usda'|'openfood'|'generic_url'
    ) {}
}
```

#### SafetyFlag (DTO)

```php
final class SafetyFlag
{
    public enum Severity: string {
        case HARD = 'hard'; // Must not ship (diagnosis, prescription, emergency trigger)
        case SOFT = 'soft'; // Warn expert, may be acceptable in context
    }

    public function __construct(
        public readonly Severity $severity,
        public readonly string $matched_pattern, // 'diagnosed with', 'dosage', 'warfarin'
        public readonly string $suggested_action, // 'Use professional-referral response', 'Flag for expert review'
        public readonly string $matched_text, // actual text from response
    ) {}
}
```

#### VerificationResult (DTO)

```php
final class VerificationResult
{
    public function __construct(
        public readonly bool $is_verified,
        public readonly array $failures, // VerificationFailure[]
        public readonly array $safety_flags, // SafetyFlag[]
        public readonly int $revision_count,
        public readonly int $latency_ms,
    ) {}
}
```

#### VerificationFailure (DTO)

```php
final class VerificationFailure
{
    public enum Type: string {
        case NOT_GROUNDED = 'not_grounded';
        case CITATION_INVALID = 'citation_invalid';
        case SAFETY_VIOLATION = 'safety_violation';
        case SCOPE_DRIFT = 'scope_drift';
        case INCOMPLETE = 'incomplete';
    }

    public function __construct(
        public readonly Type $type,
        public readonly string $claim_text,
        public readonly string $reason, // detailed explanation
    ) {}
}
```

### Stage Descriptions

#### Stage 1: Claim Extraction

**Service:** `ClaimExtractionService`

**Method:** `extract(string $response_text): Claim[]`

Uses a structured LLM prompt to identify factual claims in the response. The prompt is designed to:
- Extract only **factual** claims (not hedges, disclaimers, or subjective statements)
- Mark which claims require citations (nutrition facts, research findings, drug interactions → yes; opinions, recommendations → no)
- Infer source category (is this a nutrition fact, a research finding, a database fact, or a guideline?)

**Example:**

Input: "Magnesium improves sleep quality. Studies show that 300mg before bed can enhance deep sleep. However, more research is needed."

Output:
```
[
  Claim { text: "Magnesium improves sleep quality", requires_citation: true, inferred_source_category: "research" },
  Claim { text: "300mg before bed can enhance deep sleep", requires_citation: true, inferred_source_category: "research" },
  Claim { text: "More research is needed", requires_citation: false, inferred_source_category: null }
]
```

**LLM Prompt:**
```
Extract factual claims from this response. For each claim:
- Claim text (exact quote if possible)
- Does it need a citation? (yes if: nutrition fact, drug interaction, study finding, guideline, statistic; no if: opinion, advice, hedge, disclaimer)
- Inferred source category: nutrition|research|guideline|database

Return as JSON array: [{ claim, requires_citation, inferred_source_category }, ...]
```

#### Stage 2: Grounding

**Service:** `GroundingService`

**Method:** `ground_all_claims(Claim[] $claims, RetrievedContext $context): Claim[]`

For each claim, searches the retrieved context chunks for supporting evidence. Uses semantic similarity (via EmbeddingService from sub-project #2):

1. Generate embedding of claim text
2. Compare against embeddings of all retrieved chunks (already computed during retrieval)
3. If similarity > 0.65, mark as grounded; return matched chunk + score
4. If no match or score < 0.65, mark as not grounded

**Threshold:** 0.65 similarity. This is tunable per avatar and source type. Research claims might require 0.70; nutrition facts might be 0.60.

**Output:**
```
Claim { 
    text: "Magnesium improves sleep quality",
    grounding: GroundingResult {
        is_grounded: true,
        matched_chunk: KnowledgeChunk { content: "...", source_url: "...", citation_key: "PMID:12345" },
        similarity_score: 0.78,
        supporting_evidence: "[excerpt from matched chunk]"
    }
}
```

#### Stage 3: Citation Validation

**Service:** `CitationValidationService`

**Method:** `validate_all_citations(Claim[] $claims): Claim[]`

For claims that are grounded, extract the citation from the matched chunk's metadata and validate it. Citation validation is **deterministic** — no LLM guessing.

**Per-Source Validators:**

- **UsdaCitationValidator:** FDC ID is an integer. Check it exists in USDA FDC database (cached API call). If not found, invalid.
- **PubMedCitationValidator:** PMID is an integer. Call PubMed E-utilities `/efetch` with UID. If returns 404 or error, invalid. Cache result 24h.
- **OpenFoodFactsCitationValidator:** Citation is a 12–14 digit barcode or URL. If barcode, call Open Food Facts API `/product/<barcode>`. If URL, same as generic. If 404 or not found, invalid.
- **GenericCitationValidator:** URL validation. Check `https://...` returns 200 within 2s. Cache negative results 1h, positive 24h.

**Output:**
```
Claim {
    text: "Magnesium improves sleep quality",
    citation: CitationValidationResult {
        is_valid: true,
        validation_detail: "PMID:12345 resolved to PubMed article",
        resolved_source_url: "https://pubmed.ncbi.nlm.nih.gov/12345",
        source_type: "pubmed"
    }
}
```

**Non-Cited Claims:** If a claim has no matching chunk with metadata, mark as ungrounded (Stage 2 will catch it). Do not attempt citation validation.

#### Stage 4: Safety Classification

**Service:** `SafetyClassifier`

**Method:** `classify(string $response_text): SafetyFlag[]`

Pattern-based (not LLM) detection of hard-rule violations. Pre-authored regex and keyword lists per vertical (stored in config).

**Hard Patterns (Wellness Vertical)** — trigger professional-referral response:
- Diagnosis: "you have", "diagnosed with", "suffer from", "condition of"
- Prescription: "take", "prescribe", "dose", "dosage of [RxDrug]" (where RxDrug is from config list)
- Emergency: "chest pain", "shortness of breath", "suicidal", "self-harm", "severe allergic", "anaphylaxis"
- Specific melanoma triggers: "asymmetry", "border irregularity", "color variation", "diameter >6mm" (as per ABCDE rule, from config)

**Soft Patterns** — flag for expert review:
- "medical advice", "clinical", "treatment", "consult your doctor" (may be acceptable, but unusual phrasing)

**Scope Drift:**
- Hotel agents should never mention health/medical topics; wellness agents should never mention booking/invoicing

**Output:**
```
[
  SafetyFlag {
    severity: Severity.HARD,
    matched_pattern: "prescribe",
    suggested_action: "Use professional-referral response",
    matched_text: "I prescribe magnesium for you"
  }
]
```

#### Stage 5: Structured Review

**Service:** `StructuredReviewService`

**Method:** `review(string $response_text, VerificationFailure[] $failures_so_far): StructuredReviewResult`

LLM-based structured review. If earlier stages found failures, the LLM is given those failures and asked to evaluate whether the response can be salvaged or must be softened.

**Checklist:**
1. **Accuracy:** Does the response match the facts in the retrieved sources? No exaggerations or minimizations?
2. **Completeness:** Are all key points covered? Any obvious gaps?
3. **Scope Adherence:** Is the response within Nora's wellness scope? No diagnosis, no prescription, no clinical claims?
4. **Safety Handling:** Does it handle sensitive topics (drug interactions, mental health) appropriately? Suggests professional contact where needed?
5. **Persona:** Does it sound like Nora (warm, accessible, expert-level knowledge)? No stiff AI voice?

**LLM Prompt:**
```
Review this response against the checklist:
1. Accuracy (matches retrieved sources, no exaggeration)
2. Completeness (all key points covered)
3. Scope (wellness education, not diagnosis/prescription/clinical)
4. Safety (appropriate handling of sensitive topics, professional referral where needed)
5. Persona (warm, accessible, knowledgeable)

Sources retrieved: [list of chunks with citations]
Failures found so far: [list of validation failures]

Return JSON:
{
  passed: bool,
  issues: [{ category, detail, suggestion }, ...],
  revision_suggestion: "Specific sentences to revise or null"
}
```

**Output:**
```
StructuredReviewResult {
    passed: false,
    issues: [
        { category: "Completeness", detail: "Missing info about timing (when to take)", suggestion: "Add: 'Ideally taken 30-60 minutes before bed'" }
    ],
    revision_suggestion: "Add sentence about timing. Otherwise strong response."
}
```

### Revision Loop

If **any stage** reports a failure (not grounded, invalid citation, safety violation, or review issues), the system:

1. Collects all failures and passes them to LLM with a revision prompt
2. LLM is given: original response, retrieved context, and specific failures
3. LLM revises specific sentences or sections to address failures
4. Revised response goes back through **full pipeline** (all 5 stages again)

**Max revisions:** 2

**Fallback (after 2 failed revisions):**
- If hard safety violation: professional-referral response (pre-authored template)
- If ungrounded/unverifiable: softened response ("I found some information, but it's not strong enough to share confidently. I'd recommend talking with a wellness practitioner about this.")
- If scope drift: clarification + redirect to appropriate avatar

### Error Handling & Resilience

- **Claim extraction fails:** Log at WARN, assume response is safe, return empty claims list (no verification needed)
- **Grounding fails (embedding error):** Log, mark all claims as ungrounded (fails verification, goes to revision loop)
- **Citation lookup timeout:** Log, mark as unvalidated (treated as failure, goes to revision loop)
- **Safety classifier crashes:** Log at ERROR, fail-safe to "review response" (no generation proceeds without review)
- **LLM revision fails:** Return fallback response after 2 attempts (do not retry indefinitely)

All errors logged with structured metadata: avatar_id, message_id, error_code, latency_ms.

### Database Schema

#### verification_events table

```sql
CREATE TABLE verification_events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    vertical_slug VARCHAR(64) NOT NULL, -- 'wellness'|'hotel'
    avatar_id BIGINT UNSIGNED,
    response_text LONGTEXT NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    revision_count INT DEFAULT 0,
    failures_json JSONB, -- array of VerificationFailure serialized
    safety_flags_json JSONB, -- array of SafetyFlag serialized
    latency_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (avatar_id) REFERENCES agents(id) ON DELETE SET NULL,
    INDEX idx_conversation_verified (conversation_id, is_verified),
    INDEX idx_avatar_verified (avatar_id, is_verified),
    INDEX idx_created_at (created_at)
);
```

#### citation_validations table (optional, for audit)

```sql
CREATE TABLE citation_validations (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    verification_event_id BIGINT UNSIGNED NOT NULL,
    citation_text VARCHAR(255) NOT NULL,
    source_type VARCHAR(64), -- 'pubmed'|'usda'|'openfood'|'generic_url'
    is_valid BOOLEAN,
    validation_detail TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (verification_event_id) REFERENCES verification_events(id) ON DELETE CASCADE,
    INDEX idx_source_type (source_type)
);
```

### Caching

**Citation Validation Cache (Redis):**
- Key format: `citation:validation:{source_type}:{citation_id}` (e.g., `citation:validation:pubmed:12345`)
- Value: `{ is_valid: bool, detail: string, resolved_url: string }`
- TTL: 24 hours (positive results), 1 hour (404/not-found)

**Embedding Cache (existing from sub-project #2):**
- Reuse EmbeddingService's SHA-256 based cache for claim embeddings
- Claim text that appears multiple times in different messages benefits from cached embedding

## Phase 1 Scope

**Avatars:** Nora only

**Features in Phase 1:**
- All 5 verification stages (extraction, grounding, citation validation, safety, structured review)
- Revision loop (up to 2 attempts)
- Verification event logging
- Citation validation for USDA, PubMed, Open Food Facts (deterministic)
- Safety classification (hard + soft patterns)
- Fallback responses (professional-referral, softened)

**Features in Phase 1.1 (v1.1):**
- Cross-model critic (GPT-5.4 reviews Claude output)
- Claim decomposition (multi-hop reasoning)

**Features in Phase 2+:**
- Vision-based verification (food/skin photo analysis)
- Continuous feedback loop (expert review → prompt improvements)
- Advanced reranking on verification failures

## Testing Strategy

**Unit Tests:**
- Claim extraction with mocked LLM responses
- Grounding service with fixture embeddings and chunks
- Citation validators with recorded API responses (cassettes)
- Safety classifier with regex pattern coverage
- Structured review with mock LLM

**Feature Tests:**
- Full pipeline on Nora's golden dataset (17 cases)
  - Verify: 0 false positives on safe, grounded responses
  - Verify: ≥95% detection of injected hallucinations (fake PMIDs, fake URLs, diagnosis drift)
  - Verify: revision loop successfully re-verifies corrected responses
  - Verify: fallback responses triggered correctly on safety violations

**Integration Tests:**
- Citation validation against live PubMed E-utilities (or recorded responses)
- Grounding against actual knowledge chunks from Phase 1 sub-project #2
- End-to-end: LLM generation → retrieval → verification → event logging

**No Regressions:**
- Hotel vertical: existing agents without verification continue to work
- Full backend test suite remains green

## Cost and Safety Notes

**API Cost:**
- Claim extraction: 1 LLM call per response (~0.5¢ with Haiku)
- Structured review: 1 LLM call per response (same model as extraction)
- Citation lookups: 0.1–0.3 API calls per response (mostly cached)
- Total cost per response: ~1¢ + infrastructure (acceptable)

**Latency:**
- Claim extraction: 300–500ms
- Grounding (parallelized): 200–400ms
- Citation validation (cached): 50–150ms
- Safety classification: 10–20ms
- Structured review: 300–500ms
- **Total added latency: ~1–2 seconds median** (acceptable for health app)

**Safety:**
- Deterministic citation validation prevents hallucinated sources
- Embedding-based grounding catches most ungrounded claims
- Hard safety rules cannot be overridden (pre-authored templates)
- Revision loop allows recovery from fixable issues
- Fallback responses ensure user never sees unverified content

**Data Retention:**
- Verification events logged indefinitely for audit
- Citation validation results cached 24h in Redis, then expired
- No user content retained beyond conversation history

## Alternatives Considered

- **All-LLM-at-once verification:** Rejected — single-model bias, harder to debug individual failures, fewer opportunities for optimization
- **Asynchronous verification (stream, then verify):** Rejected — spec requires "before user sees it", and wellness domain demands safety-first
- **Semantic similarity only (no citation validation):** Rejected — high risk of missing hallucinated sources
- **Cross-model critic in Phase 1:** Rejected — Phase 1 baseline should be single-model, upgrade in v1.1 when quality is proven

## Consequences

- Nora now ships with a rigorous verification pipeline. Every claim is grounded, every citation is validated, diagnosis/prescription drift is caught deterministically.
- The abstraction is source-agnostic (validators per source type). Phase 2 avatars (Luna, Integra, etc.) add their own sources without touching verification engine code.
- Added latency (1–2s per response) is acceptable and documented as a quality investment. The business bears the cost, not the user.
- Verification events are fully audited for regulatory compliance (GDPR, CCPA, future medical device regulations).
- Expert-review queue (Phase 2) will be fed by low-confidence flags from this pipeline.
- This design sets the pattern for the cross-model critic upgrade in v1.1 — the infrastructure is already modular enough to swap in GPT-5.4 as the reviewer.
