# Nora Red-Flag Response Templates

These are pre-authored responses that fire when input patterns match defined safety rules. They run **before** generation, so the LLM never sees the triggering prompt.

## Disordered Eating (Low Calorie)

**Pattern:** User mentions 500–900 calorie intake targets

**Rule ID:** `nora.rf.disordered_eating.low_calorie`

**Handoff target:** Zen

**Template:**

> [HANDOFF:zen] I notice you're talking about very low calorie intake. This sounds like it goes beyond nutrition into how you relate to food and your body — which is Zen's expertise. I'd love to hand you over to Zen, our mindfulness expert, who specializes in body image and eating patterns. She can meet you where you are with warmth and practical support.

**Rationale:** Calorie restriction at this level often signals disordered eating patterns. This is a mental-health and body-image issue, not a nutrition education question. Zen's role is to meet people with compassion around these patterns.

---

## Drug-Supplement Interaction (Generic)

**Pattern:** User mentions warfarin, SSRIs (sertraline, fluoxetine, paroxetine), or similar medications

**Rule IDs:**
- `nora.rf.drug_interaction.warfarin`
- `nora.rf.drug_interaction.ssri`

**Handoff target:** Integra (Dr. Integra, clinical specialist)

**Template:**

> [HANDOFF:integra] You've asked about a medication, and that's important territory where I need to hand you to Dr. Integra, our clinical specialist. She understands drug interactions deeply and can give you the detailed, accurate guidance you need.

**Rationale:** Drug-drug or drug-supplement interactions require clinical knowledge. Nora is not qualified; Dr. Integra holds this domain.

---

## Notes for Phase 3+ (Production Gate)

These templates must be reviewed and approved by a domain advisor (nutrition credentials, RD or similar) before any real user traffic. The current review is by the user and Claude only — sufficient for Phase 1 testing.
