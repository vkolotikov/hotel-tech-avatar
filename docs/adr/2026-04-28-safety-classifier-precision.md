# ADR: SafetyClassifier — Claim-Based Precision Patterns

**Date:** 2026-04-28
**Status:** Accepted
**Supersedes:** n/a

## Context

`App\Services\Verification\SafetyClassifier` is the output-side safety
check that runs after every wellness avatar response. When it returns
a HARD-severity flag, `VerificationService` records a
`SAFETY_VIOLATION` failure, and `GenerationService` (after up to two
revision attempts) replaces the entire reply with a generic localized
fallback ("I'd suggest checking in with a healthcare professional on
this one.").

The original v1 classifier used case-insensitive substring matching on
9 phrases: `'diagnosed with'`, `'prescribe'`, `'dosage'`, `'chest
pain'`, `'shortness of breath'`, `'suicidal'`, `'self-harm'`, `'severe
allergic'`, `'anaphylaxis'`. Substring matching produced a steady
stream of false positives in production traffic and on the eval
harness:

- *"people **diagnosed with** prediabetes often experience..."* — educational mention, fired.
- *"talk to your **prescribe**r about dosage"* — referral, fired.
- *"if you experience **chest pain**, please call 911"* — *correct* red-flag handling, fired.
- *"if you are **suicidal**, please call 988"* — crisis referral, fired.

Every false positive replaced an otherwise-correct, useful avatar
reply with the generic fallback. This is worse for both safety and UX:

- **UX**: the user got no actionable info, no referral, no PMID-cited
  context — just a vague "see a professional".
- **Safety**: the avatar's correct red-flag referral language was the
  *desired* behavior. Suppressing it makes the system silently
  *worse* at safety, not better — the user doesn't see "call 911",
  they see "see a professional later".

The Integra avatar was the most affected because functional-medicine
education legitimately involves discussing labs, conditions, and
red-flag symptoms in referral context. A 2026-04-28 smoke run had
1/6 wellness avatars failing solely because of this false positive.

## Decision

Tighten the HARD patterns to match **first-person claims about the
user** — preserving the original v1.0 intent ("no diagnosis, no
prescription, no prescription-drug dosing in any user-facing wellness
output, ever" — `CLAUDE.md`) without the educational/referral false
positives.

The three retained HARD rules use PCRE patterns rather than substring
matching:

1. **`diagnosed-with-user`** — fires on "you have/are/'ve been diagnosed
   with X" or "I diagnose you with X". Allows "people diagnosed with X
   often..." (educational).

2. **`prescription-by-avatar`** — fires on first-person prescription
   verbs ("I prescribe", "I will prescribe", "I'm prescribing") or
   "you should take Xmg". Allows "talk to your prescriber" (referral).

3. **`dosing-instruction`** — fires on imperative dose patterns ("take
   500mg twice daily", "your dosage should be 1000mg"). Allows
   "dosage is the prescriber's call" (abstract discussion).

The previously HARD symptom keywords (`'chest pain'`, `'shortness of
breath'`, `'severe allergic'`, `'anaphylaxis'`, `'suicidal'`,
`'self-harm'`) are removed from the output classifier entirely. These
concerns are handled correctly by the existing `agents.red_flag_rules_json`
mechanism on the **input** side: when the *user* mentions these terms,
deterministic canned responses fire from the prompt builder. The
output-side classifier had been double-checking the wrong direction
(avatar mentioned the term → fail) and producing the false positives
described above.

The SOFT patterns (`'medical advice'`, `'clinical'`, `'treatment'`,
`'consult your doctor'`) keep substring matching — soft flags only
trigger review/revision, never the fallback path, so over-firing is
low-cost.

## Consequences

**Positive**

- Integra, Aura, and any avatar that legitimately discusses labs,
  conditions, or red-flag symptoms in referral context now passes
  cleanly. The 2026-04-28 smoke run is expected to return to 6/6
  passing.
- The original safety intent — preventing the avatar from making
  diagnoses, prescriptions, or dosing claims about the user — is
  preserved and now applies precisely.
- 16 unit tests in `SafetyClassifierTest` exercise both the positive
  cases (real claims fail) and the false-positive cases (educational,
  referral, abstract discussion pass).

**Negative / accepted risk**

- Output-side detection of avatar authoritatively declaring "you are
  suicidal" without conditionals is no longer a hard rule. Mitigation:
  (a) input-side `red_flag_rules_json` deterministically handles
  user-mentioned crisis terms; (b) the Stage 5 structured-review LLM
  critic in `VerificationService` catches free-form tone problems on
  every revision pass; (c) we have not observed this pattern in eval
  or production traffic. If it does emerge, a targeted rule lands
  here without re-litigating the broader design.
- A future avatar that *should* be able to say "you have hypertension"
  in a research-summary context (none currently in scope) would still
  hit `diagnosed-with-user`. We accept this — the wellness vertical's
  hard rules forbid that phrasing entirely, regardless of vertical.

## Rollback

Revert `app/Services/Verification/SafetyClassifier.php` to the
substring-matching version. The interface and call sites are
unchanged; the rollback is a single-file revert. Tests in
`tests/Unit/Services/Verification/SafetyClassifierTest.php` would
need to be reverted alongside.

## Sign-off

This change loosens the *false-positive surface* of the classifier
(reducing replies wrongly replaced with the localized fallback) while
preserving the original "no diagnosis / no prescription / no dosing"
intent. It does not loosen the wellness-vertical safety rules
themselves — those remain enforced via (a) the prompt builder's
red-flag and scope blocks, (b) the input-side `red_flag_rules_json`
trigger, (c) the LLM-driven structured review in Stage 5 of
`VerificationService`. Domain-advisor sign-off is required before
deploy on safety-significant code paths; this ADR documents the
rationale for that review.
