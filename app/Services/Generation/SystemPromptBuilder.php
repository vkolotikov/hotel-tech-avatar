<?php

declare(strict_types=1);

namespace App\Services\Generation;

use App\Models\Agent;
use App\Models\UserProfile;
use App\Services\Knowledge\RetrievedContext;

/**
 * Builds the full system prompt from every admin-configured field on an
 * Agent so nothing the super-admin authored gets silently dropped at
 * call time.
 *
 * Shared by GenerationService (chat) and App\Eval\LiveResolver (eval
 * harness) so the model sees the same context in both paths.
 */
final class SystemPromptBuilder
{
    private int $maxKnowledgeChars;

    public function __construct(?int $maxKnowledgeChars = null)
    {
        $this->maxKnowledgeChars = $maxKnowledgeChars
            ?? (int) config('services.openai.max_knowledge_chars', 12000);
    }

    /**
     * Full prompt with the trailing conversation-style + JSON contract.
     * Use this from chat (GenerationService).
     */
    public function build(
        Agent $agent,
        ?RetrievedContext $retrieval = null,
        ?UserProfile $userProfile = null,
        ?string $userDisplayName = null,
    ): string {
        return $this->compose($agent, $retrieval, $userProfile, $userDisplayName, /* includeStyle = */ true);
    }

    /**
     * Same as build() but without the JSON-contract block. Use this for
     * eval runs so the model answers in plain prose and string-match
     * assertions have something natural to bite on — wrapping every
     * response in {"reply": "..."} would make assertions match against
     * the JSON wrapper text.
     */
    public function buildForEval(
        Agent $agent,
        ?RetrievedContext $retrieval = null,
        ?UserProfile $userProfile = null,
        ?string $userDisplayName = null,
    ): string {
        return $this->compose($agent, $retrieval, $userProfile, $userDisplayName, /* includeStyle = */ false);
    }

    private function compose(
        Agent $agent,
        ?RetrievedContext $retrieval,
        ?UserProfile $userProfile,
        ?string $userDisplayName,
        bool $includeStyle,
    ): string {
        $parts = [];

        // 0. Language directive — sits at the top so it overrides any
        //    English-by-default behaviour the model defaults to. We
        //    feed BOTH the ISO code and the language name so the model
        //    can't confuse "en" with "English". Falls through silently
        //    when the user hasn't picked a language yet.
        $langPart = $this->renderLanguageDirective($userProfile);
        if ($langPart !== null) {
            $parts[] = $langPart;
        }

        // 1. Identity — always rendered so "what's your name?" works
        //    regardless of what's in system_instructions.
        $identityLine = "You are {$agent->name}";
        if ($agent->role) {
            $identityLine .= ", the {$agent->role}";
        }
        if ($agent->description) {
            $identityLine .= ". {$agent->description}";
        } else {
            $identityLine .= '.';
        }
        $parts[] = "# Who you are\n{$identityLine}\n\n"
            . "If asked your name, say you are {$agent->name}. If asked what you do, summarise your role briefly.";

        // 2. User context — who the avatar is talking to. Comes early
        //    so the persona / scope / safety rules below can read this
        //    state when deciding how to phrase advice.
        $userContext = $this->renderUserContext($userProfile, $userDisplayName);
        if ($userContext !== null) {
            $parts[] = $userContext;
        }

        // 3. Authored instructions (the long-form system prompt).
        if (!empty(trim((string) $agent->system_instructions))) {
            $parts[] = "# Instructions\n" . trim($agent->system_instructions);
        }

        // 3. Structured persona (tone, style, forbidden phrases).
        if ($agent->persona_json && is_array($agent->persona_json)) {
            $section = $this->renderPersona($agent->persona_json);
            if ($section !== null) $parts[] = $section;
        }

        // 4. Scope guardrails.
        if ($agent->scope_json && is_array($agent->scope_json)) {
            $section = $this->renderScope($agent->scope_json);
            if ($section !== null) $parts[] = $section;
        }

        // 5. Red-flag rules — hard overrides with canned responses.
        if ($agent->red_flag_rules_json && is_array($agent->red_flag_rules_json)) {
            $section = $this->renderRedFlag($agent->red_flag_rules_json);
            if ($section !== null) $parts[] = $section;
        }

        // 6. Handoffs.
        if ($agent->handoff_rules_json && is_array($agent->handoff_rules_json)) {
            $section = $this->renderHandoff($agent->handoff_rules_json);
            if ($section !== null) $parts[] = $section;
        }

        // 7. Inline knowledge text.
        if (!empty(trim((string) $agent->knowledge_text))) {
            $knowledge = mb_substr($agent->knowledge_text, 0, $this->maxKnowledgeChars);
            $parts[] = "# Knowledge Base (inline)\n{$knowledge}";
        }

        // 8. Retrieved evidence (Phase-1 retrieval — pgvector chunks).
        if ($retrieval && $retrieval->chunk_count > 0) {
            $section = $this->renderRetrieval($retrieval);
            if ($section !== null) $parts[] = $section;
        }

        // 9. Conversation style + JSON contract. Chat-only.
        if ($includeStyle) {
            $parts[] = $this->conversationStyleBlock();
        } else {
            // Eval-mode enforcement. These don't conflict with chat (which
            // has the full JSON contract) and close the gap between chat
            // behaviour and what the golden datasets assert.
            $parts[] = $this->evalEnforcementBlock();
        }

        return implode("\n\n---\n\n", $parts);
    }

    private function evalEnforcementBlock(): string
    {
        return "# Response rules\n"
            . "- When a red-flag rule or handoff rule matches the user's question, FOLLOW IT EXACTLY. Use the canned text verbatim. Do NOT generate your own version and do NOT append extra advice.\n"
            . "- When handing off, name the target avatar explicitly by name (Dr. Integra, Nora, Luna, Zen, Axel, Aura).\n"
            . "- For any factual claim about research, evidence, mechanisms, or reference ranges, cite at least one source marker: (PMID:XXXXXXXX) or [n]. If you don't have a specific citation, say so plainly rather than claim research supports it.\n"
            . "- Reply in plain prose, NOT JSON. No markdown emphasis around words.";
    }

    private function renderPersona(array $persona): ?string
    {
        $lines = [];
        foreach (['voice' => 'Voice', 'tone' => 'Tone', 'length_target' => 'Length target', 'pace' => 'Pace'] as $key => $label) {
            if (!empty($persona[$key]) && is_string($persona[$key])) {
                $lines[] = "- {$label}: " . trim($persona[$key]);
            }
        }
        foreach (['style_rules' => 'Style rules', 'forbidden_phrases' => 'Never say', 'favourite_phrases' => 'Phrases you use'] as $key => $label) {
            if (!empty($persona[$key]) && is_array($persona[$key])) {
                $items = array_filter(array_map('trim', array_map('strval', $persona[$key])));
                if (!empty($items)) {
                    $lines[] = "- {$label}: " . implode('; ', $items);
                }
            }
        }
        return empty($lines) ? null : "# Persona\n" . implode("\n", $lines);
    }

    private function renderScope(array $scope): ?string
    {
        $lines = [];
        $isList = array_keys($scope) === range(0, count($scope) - 1);
        if ($isList) {
            foreach ($scope as $entry) {
                if (!is_array($entry)) continue;
                $topic = $entry['topic'] ?? null;
                $resp  = $entry['response'] ?? null;
                if ($topic && $resp) {
                    $lines[] = "- Refuse **{$topic}**. Redirect with: \"" . trim($resp) . "\"";
                }
            }
        } else {
            if (!empty($scope['in_scope']) && is_array($scope['in_scope'])) {
                $lines[] = '- In scope: ' . implode(', ', array_map('strval', $scope['in_scope']));
            }
            if (!empty($scope['out_of_scope']) && is_array($scope['out_of_scope'])) {
                $lines[] = '- Out of scope: ' . implode(', ', array_map('strval', $scope['out_of_scope']));
            }
            if (!empty($scope['out_of_scope_policy']) && is_string($scope['out_of_scope_policy'])) {
                $lines[] = '- Policy: ' . trim($scope['out_of_scope_policy']);
            }
        }
        return empty($lines) ? null : "# Scope\n" . implode("\n", $lines);
    }

    private function renderRedFlag(array $rules): ?string
    {
        $isAssoc = array_keys($rules) !== range(0, count($rules) - 1);
        if ($isAssoc) return null;

        $lines = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;

            $trigger = null;
            if (!empty($rule['keywords']) && is_array($rule['keywords'])) {
                $trigger = 'any of: ' . implode(', ', array_map('strval', $rule['keywords']));
            } elseif (!empty($rule['pattern_regex']) && is_string($rule['pattern_regex'])) {
                $trigger = "pattern `{$rule['pattern_regex']}`";
            }
            if (!$trigger) continue;

            $resp = $rule['response'] ?? $rule['canned_response'] ?? null;
            if ($resp) {
                $lines[] = "- If the user message contains {$trigger} → reply ONLY with: \"" . trim($resp) . "\"";
            } else {
                $cat = $rule['category'] ?? 'safety';
                $lines[] = "- If the user message contains {$trigger} → treat as {$cat}, hand off and do not generate advice.";
            }
        }

        if (empty($lines)) return null;

        return "# Red-flag safety rules (HARD OVERRIDES — do NOT generate past these)\n"
            . implode("\n", $lines);
    }

    private function renderHandoff(array $rules): ?string
    {
        $lines = [];
        $isAssoc = array_keys($rules) !== range(0, count($rules) - 1);
        if ($isAssoc) {
            foreach ($rules as $target => $tags) {
                if (!is_string($tags)) continue;
                $lines[] = "- For topics tagged ({$tags}) → your FIRST sentence MUST mention **{$target}** by name.";
            }
        } else {
            foreach ($rules as $entry) {
                if (!is_array($entry)) continue;
                $trigger = $entry['trigger'] ?? null;
                $ref     = $entry['referral'] ?? null;
                if ($trigger && $ref) {
                    $lines[] = "- When the user's concern is **{$trigger}**, your FIRST sentence MUST start with: \"" . trim($ref) . "\"";
                }
            }
        }
        if (empty($lines)) return null;

        return "# Handoffs (CRITICAL — failure to follow makes your reply incorrect)\n"
            . implode("\n", $lines)
            . "\n- You MUST mention the target avatar by name (Dr. Integra, Nora, Luna, Zen, Axel, or Aura) somewhere in your reply for any matching handoff.\n"
            . "- Giving direct advice on a handoff topic without naming the target avatar is a rule violation. The other avatar is the one who goes deep; you set up the handoff.\n"
            . "- If multiple handoff rules could apply (e.g. both stress-related and nutrition-related), name whichever avatar maps to the user's PRIMARY concern in their message.";
    }

    /**
     * Renders the "About the user" block — name + body baseline +
     * goals + safety-relevant conditions / medications / allergies.
     * Returns null if there's no useful context to share, so the
     * prompt stays clean for fresh users who haven't filled in
     * their profile yet.
     *
     * Two responsibilities:
     *   1. Personalisation — avatar uses display_name when addressing
     *      the user, knows their pronouns, tailors examples to their
     *      goals + dietary preferences.
     *   2. Safety — conditions / medications / allergies turn into
     *      explicit don't-cross-this-line rules. e.g. allergic-to-
     *      peanuts users must NEVER be recommended peanut-containing
     *      foods regardless of nutritional fit.
     */
    /**
     * Renders the language preference as a hard top-of-prompt rule. We
     * pin to nine supported codes; anything else (or null) skips the
     * block, leaving the model in its default English behaviour.
     */
    private function renderLanguageDirective(?UserProfile $profile): ?string
    {
        $code = $profile?->preferred_language;
        if (!$code) return null;

        $name = match ($code) {
            'en' => 'English',
            'es' => 'Spanish (Español)',
            'fr' => 'French (Français)',
            'de' => 'German (Deutsch)',
            'pl' => 'Polish (Polski)',
            'it' => 'Italian (Italiano)',
            'ru' => 'Russian (Русский)',
            'uk' => 'Ukrainian (Українська)',
            'lv' => 'Latvian (Latviešu)',
            default => null,
        };
        if (!$name) return null;

        return "# Language (HIGHEST priority — overrides defaults)\n"
            . "EVERY word you produce must be in {$name} (ISO {$code}). This is non-negotiable.\n"
            . "Applies to: the `reply` field, every `suggestions` string, all markdown headings, table headers and cells, bullet labels, follow-up questions, error messages, refusals.\n"
            . "Exceptions (keep as-is): citation tokens (PMID:1234, USDA FDC ID:173410, URLs), avatar names (Nora, Luna, Zen, Axel, Aura, Dr. Integra), the brand name (Hexalife), and exact food/medicine/scientific names that don't have a natural translation.\n"
            . "If the user writes in a different language, still reply in {$name} unless they explicitly ask to switch. Don't auto-detect their typing language and switch back — they picked {$name} on purpose.\n"
            . "If your training default is English and you catch yourself drafting in English, STOP and rewrite in {$name} before sending.";
    }

    private function renderUserContext(?UserProfile $profile, ?string $userDisplayName): ?string
    {
        $name = null;
        if ($profile?->display_name) {
            $name = trim((string) $profile->display_name);
        } elseif ($userDisplayName) {
            $name = trim($userDisplayName);
        }

        // Nothing meaningful to share — return null, prompt stays lean.
        if (!$name && !$profile) {
            return null;
        }

        $lines = [];

        if ($name) {
            $first = explode(' ', $name)[0];
            $lines[] = "- The user's name is **{$name}**. Address them as \"{$first}\" when greeting or making a personal point.";
        }

        if ($profile) {
            // ─── Identity / demographics ─────────────────────────────────
            if (!empty($profile->pronouns)) {
                $lines[] = "- Pronouns: {$profile->pronouns}.";
            }
            if (!empty($profile->age_band) || !empty($profile->birth_year)) {
                $age = $profile->birth_year
                    ? (((int) date('Y')) - (int) $profile->birth_year) . " years old"
                    : "in the {$profile->age_band} age band";
                $lines[] = "- Age: {$age}.";
            }
            if (is_array($profile->ethnicity) && !empty($profile->ethnicity)) {
                $eth = implode(', ', array_map('strval', $profile->ethnicity));
                // Used silently — never echo back; informs evidence selection.
                $lines[] = "- Background (used silently for genetic-risk-aware framing, never named back): {$eth}.";
            }

            // ─── Body baseline ───────────────────────────────────────────
            $bodyParts = [];
            if (!empty($profile->sex_at_birth)) {
                $bodyParts[] = match ($profile->sex_at_birth) {
                    'F' => 'female at birth',
                    'M' => 'male at birth',
                    'I' => 'intersex',
                    default => null,
                };
            }
            if (!empty($profile->height_cm)) $bodyParts[] = "{$profile->height_cm} cm tall";
            if (!empty($profile->weight_kg)) $bodyParts[] = "{$profile->weight_kg} kg";
            if (!empty($profile->waist_cm))  $bodyParts[] = "{$profile->waist_cm} cm waist";
            if (!empty($profile->activity_level)) $bodyParts[] = "{$profile->activity_level} activity level";
            $bodyParts = array_filter($bodyParts);
            if (!empty($bodyParts)) {
                $lines[] = '- Body baseline: ' . implode(', ', $bodyParts) . '.';
            }

            // ─── Day shape ───────────────────────────────────────────────
            $dayParts = [];
            if (!empty($profile->job_type)) {
                $dayParts[] = match ($profile->job_type) {
                    'desk' => 'desk job (sitting most of the day)',
                    'mixed' => 'mixed sitting/standing work',
                    'feet' => 'on-feet work',
                    'physical' => 'physical / manual labour',
                    'shift' => 'shift work',
                    'none' => 'not currently working',
                    default => $profile->job_type,
                };
            }
            if (!empty($profile->outdoor_minutes_band)) {
                $dayParts[] = "outdoors {$profile->outdoor_minutes_band} min/day";
            }
            if (!empty($profile->wellness_time_band)) {
                $dayParts[] = "{$profile->wellness_time_band} min/day available for wellness";
            }
            if (!empty($dayParts)) {
                $lines[] = '- Daily shape: ' . implode(', ', $dayParts) . '. Tailor recommendations to fit.';
            }

            // ─── Sleep ───────────────────────────────────────────────────
            $sleepParts = [];
            if (!empty($profile->sleep_hours_target)) $sleepParts[] = "{$profile->sleep_hours_target}h target";
            if (!empty($profile->sleep_quality)) $sleepParts[] = "current quality: {$profile->sleep_quality}";
            if (!empty($profile->chronotype)) $sleepParts[] = "chronotype: {$profile->chronotype}";
            if (!empty($sleepParts)) {
                $lines[] = '- Sleep: ' . implode(', ', $sleepParts) . '.';
            }

            // ─── Habits ──────────────────────────────────────────────────
            $habitParts = [];
            if (!empty($profile->smoking_status))   $habitParts[] = "smoking: {$profile->smoking_status}";
            if (!empty($profile->alcohol_freq))     $habitParts[] = "alcohol: {$profile->alcohol_freq}";
            if (!empty($profile->caffeine_freq))    $habitParts[] = "caffeine: {$profile->caffeine_freq}";
            if (!empty($profile->stress_level))     $habitParts[] = "stress: {$profile->stress_level}";
            if (!empty($habitParts)) {
                $lines[] = '- Habits: ' . implode(', ', $habitParts) . '.';
            }

            // ─── Eating context ──────────────────────────────────────────
            $eatParts = [];
            if (!empty($profile->eating_pattern))    $eatParts[] = $profile->eating_pattern;
            if (!empty($profile->eating_schedule))   $eatParts[] = "schedule: {$profile->eating_schedule}";
            if (!empty($profile->cooking_skill))     $eatParts[] = "cooks at {$profile->cooking_skill} level";
            if (!empty($profile->cooking_time_band)) $eatParts[] = "{$profile->cooking_time_band} min available to cook";
            if (!empty($eatParts)) {
                $lines[] = '- Eating context: ' . implode(', ', $eatParts) . '.';
            }
            if (is_array($profile->dietary_flags) && !empty($profile->dietary_flags)) {
                $lines[] = '- Dietary preferences: ' . implode(', ', array_map('strval', $profile->dietary_flags)) . '. Respect these in any food suggestions.';
            }
            if (is_array($profile->intolerances) && !empty($profile->intolerances)) {
                $lines[] = '- Food intolerances (not allergies but cause symptoms): ' . implode(', ', array_map('strval', $profile->intolerances)) . '. Avoid recommending these.';
            }

            // ─── Goals + motivation ──────────────────────────────────────
            if (is_array($profile->goals) && !empty($profile->goals)) {
                $lines[] = '- Stated goals: ' . implode(', ', array_map('strval', $profile->goals)) . '.';
            }
            $motiParts = [];
            if (!empty($profile->motivation_trigger)) $motiParts[] = "started by: {$profile->motivation_trigger}";
            if (!empty($profile->motivation_text))    $motiParts[] = "in their words: \"" . trim($profile->motivation_text) . "\"";
            if (!empty($profile->goal_timeline))      $motiParts[] = "timeline: {$profile->goal_timeline}";
            if (!empty($profile->goal_confidence))    $motiParts[] = "self-rated confidence to hit goals: {$profile->goal_confidence}/10";
            if (!empty($motiParts)) {
                $lines[] = '- Motivation: ' . implode('; ', $motiParts) . '. Reference this naturally — never quote it back verbatim.';
            }

            // ─── Life context ────────────────────────────────────────────
            $lifeParts = [];
            if (!empty($profile->living_situation)) $lifeParts[] = match ($profile->living_situation) {
                'alone' => 'lives alone',
                'partner' => 'lives with partner',
                'family-kids' => 'lives with family including kids',
                'parents' => 'lives with parents',
                'roommates' => 'lives with roommates',
                default => $profile->living_situation,
            };
            if (!empty($profile->travel_frequency) && $profile->travel_frequency !== 'rarely') {
                $lifeParts[] = "travels {$profile->travel_frequency}";
            }
            if ($profile->budget_conscious === true) {
                $lifeParts[] = 'asked for budget-friendly suggestions';
            }
            if (!empty($lifeParts)) {
                $lines[] = '- Life context: ' . implode(', ', $lifeParts) . '.';
            }

            // ─── Female health (only when relevant) ──────────────────────
            if (!empty($profile->female_status) && $profile->female_status !== 'prefer-not-to-say') {
                $femParts = [match ($profile->female_status) {
                    'regular' => 'regular menstrual cycle',
                    'irregular' => 'irregular menstrual cycle',
                    'trying' => 'trying to conceive',
                    'pregnant' => 'pregnant',
                    'breastfeeding' => 'breastfeeding',
                    'perimenopause' => 'perimenopause',
                    'menopause' => 'in menopause',
                    'post-menopause' => 'post-menopause',
                    default => $profile->female_status,
                }];
                if ($profile->pregnancy_weeks)      $femParts[] = "{$profile->pregnancy_weeks} weeks pregnant";
                if ($profile->breastfeeding_months) $femParts[] = "breastfeeding for {$profile->breastfeeding_months} months";
                if ($profile->cycle_length_days)    $femParts[] = "cycle ~{$profile->cycle_length_days} days";
                if (!empty($profile->contraception) && $profile->contraception !== 'prefer-not-to-say') {
                    $femParts[] = "contraception: {$profile->contraception}";
                }
                $lines[] = '- Reproductive health: ' . implode(', ', $femParts) . '.';
            }

            // ─── Coaching style (shapes EVERY reply's voice) ─────────────
            $coachParts = [];
            if (!empty($profile->coaching_tone))    $coachParts[] = "tone: {$profile->coaching_tone}";
            if (!empty($profile->coaching_detail))  $coachParts[] = "detail: {$profile->coaching_detail}";
            if (!empty($profile->coaching_pace))    $coachParts[] = "pace: {$profile->coaching_pace}";
            if (!empty($profile->coaching_style))   $coachParts[] = "structure: {$profile->coaching_style}";
            if (!empty($profile->accountability_style)) $coachParts[] = "accountability: {$profile->accountability_style}";
            if (!empty($coachParts)) {
                $lines[] = '';
                $lines[] = '## Coaching preferences (apply on EVERY reply):';
                $lines[] = '- ' . implode(', ', $coachParts) . '.';
                $lines[] = '- Match this voice — gentle for "gentle", direct + concise for "direct", thorough markdown structure for "thorough", short and punchy for "brief".';
            }

            // ─── Safety — must be explicit, not buried. ──────────────────
            $safetyLines = [];
            if (is_array($profile->conditions) && !empty($profile->conditions)) {
                $cond = implode(', ', array_map('strval', $profile->conditions));
                $safetyLines[] = "- Diagnosed conditions: {$cond}. Tailor lifestyle suggestions appropriately and flag anything outside the user's stated condition pattern as a clinician question.";
            }
            if (is_array($profile->medications) && !empty($profile->medications)) {
                $meds = implode(', ', array_map('strval', $profile->medications));
                $safetyLines[] = "- Current medications: {$meds}. NEVER advise on dosing, interactions, or stopping these — defer to the prescribing clinician (or hand off to Dr. Integra for general framing).";
            }
            if (is_array($profile->allergies) && !empty($profile->allergies)) {
                $aller = implode(', ', array_map('strval', $profile->allergies));
                $safetyLines[] = "- Allergies: {$aller}. NEVER recommend foods or supplements containing these, regardless of how good the fit otherwise.";
            }
            if (is_array($profile->past_injuries) && !empty($profile->past_injuries)) {
                $inj = implode(', ', array_map('strval', $profile->past_injuries));
                $safetyLines[] = "- Past major injuries: {$inj}. Default to safer movement modifications (Axel especially) and ask before suggesting high-impact exercise that loads these areas.";
            }
            if (is_array($profile->mental_health) && !empty($profile->mental_health)) {
                $mh = array_map('strval', $profile->mental_health);
                if (in_array('eating-disorder', $mh, true)) {
                    $safetyLines[] = "- Eating disorder history: NEVER recommend caloric restriction, fasting, weight-loss-focused framing, or appearance-focused goals. Frame all nutrition as energy, performance, and joy. Defer to clinician for any concerning pattern.";
                }
                $other = array_diff($mh, ['eating-disorder']);
                if (!empty($other)) {
                    $safetyLines[] = "- Mental health context: " . implode(', ', $other) . ". Never minimise or armchair-diagnose; use language that respects the lived experience and refers to the user's clinician on anything clinical.";
                }
            }
            if (is_array($profile->family_history) && !empty($profile->family_history)) {
                $fh = implode(', ', array_map('strval', $profile->family_history));
                $safetyLines[] = "- Family history (first-degree relatives): {$fh}. Inform Dr. Integra's risk framing; do NOT volunteer scary statistics unprompted.";
            }
            if ($profile->female_status === 'pregnant' || $profile->female_status === 'breastfeeding') {
                $safetyLines[] = "- PREGNANCY/BREASTFEEDING SAFE MODE: avoid intermittent fasting, caloric restriction, high-dose supplements, untested herbs, hot saunas, contraindicated medications. When in doubt, defer to OB/midwife.";
            }
            if (!empty($safetyLines)) {
                $lines[] = '';
                $lines[] = '## Safety constraints (HARD — apply on every turn):';
                foreach ($safetyLines as $sl) $lines[] = $sl;
            }
        }

        return "# About the user\n" . implode("\n", $lines);
    }

    private function renderRetrieval(RetrievedContext $ctx): ?string
    {
        if (empty($ctx->chunks)) return null;

        $lines = [];
        foreach ($ctx->chunks as $i => $chunk) {
            $n = $i + 1;
            $citation = $chunk->citation_key ?: $chunk->source_name;
            $excerpt = mb_substr($chunk->content, 0, 800);
            $lines[] = "[{$n}] ({$citation}) — {$excerpt}\nSource: {$chunk->source_url}";
        }

        return "# Evidence — the ONLY sources you may cite\n"
            . implode("\n\n", $lines)
            . "\n\n## Citation rules (strict — the response is rejected if violated)\n"
            . "- If you make a factual claim about research, mechanism, or reference ranges, cite AT LEAST ONE source from the numbered list above.\n"
            . "- Use the exact citation key format shown in parentheses — e.g. `(PMID:12345678)` or `(USDA FDC ID: 173410)` — not author-year references like `(Smith et al., 2020)`, `(Hill et al. 2014)`, or vague phrasings like `studies show` without a citation.\n"
            . "- DO NOT cite sources from your training data that are not in the list above. Every cited PMID / FDC ID / URL must appear in the evidence section.\n"
            . "- If the evidence above does not support the claim you want to make, change the claim or say plainly \"I don't have a specific source for this\" rather than inventing one.\n\n"
            . "## Voice rules (the user sees a natural conversation, not a literature review)\n"
            . "- NEVER name the source system in prose. Do NOT write \"PubMed research shows\", \"per PubMed\", \"USDA FoodData Central lists\", \"according to Open Food Facts\", \"meta-analyses on PubMed\", or any equivalent phrasing.\n"
            . "- Citations are silent evidence markers, not narrated. The UI renders them under an info icon; the user does not see them inline.\n"
            . "- Speak as if you just know the fact. Good: \"Fermented foods shift the gut microbiome (PMID:32860802).\" Bad: \"According to PubMed research (PMID:32860802), fermented foods shift the gut microbiome.\"";
    }

    private function conversationStyleBlock(): string
    {
        // The output JSON shape is enforced by Structured Outputs
        // (response_format / text.format with strict json_schema), so
        // this block no longer describes the schema — gpt-5.5's guide
        // explicitly recommends letting Structured Outputs do that
        // work. We focus here on *what to say* and *how to phrase it*.
        return "# Goal\n"
            . "Answer the user's wellness question. They came to learn — give them the answer first, every time.\n\n"
            . "# Personality\n"
            . "Warm, direct, and confident. Talk like a knowledgeable friend, not a textbook. Match the user's energy — concise when they're brief, more detailed when they ask for depth. Use the coaching preferences listed under \"About the user\" (tone, detail, pace) on every reply.\n\n"
            . "# Success criteria\n"
            . "A reply is good when:\n"
            . "- The user's question is answered in the first sentence or two.\n"
            . "- Any factual claim about research, mechanisms, or reference ranges carries an evidence citation from the supplied evidence section.\n"
            . "- Length matches the question: 2–4 sentences for casual chat, longer when the user asked for a plan, comparison, or breakdown.\n"
            . "- Every word is in the user's preferred language (declared at the top of this prompt).\n"
            . "- Red-flag and handoff rules win over everything else — when they match, follow them verbatim.\n\n"
            . "# Output rules\n"
            . "- Return the answer in the `reply` field as plain prose. Markdown inside the prose is fine when it carries structure (see below).\n"
            . "- Use `suggestions` for 0 to 3 short user-tappable next-question shortcuts, written from the user's perspective (≤50 chars each, in the user's language). An empty array is fine — don't pad with generic items.\n"
            . "- When a red-flag rule matches, reply with the canned text and leave suggestions empty.\n\n"
            . "## When the user asks for structure (meal plan, schedule, comparison, recipe, list)\n"
            . "Deliver it in full. Don't announce a plan and then stop short, and don't trim details to keep things brief — the user asked for substance.\n"
            . "Format with markdown so the mobile renderer lays it out cleanly:\n"
            . "- **Tables** for attribute-by-attribute comparisons (foods × macros, supplements × dose, exercises × sets/reps):\n"
            . "  ```\n"
            . "  | Item | Calories | Protein |\n"
            . "  |------|----------|---------|\n"
            . "  | Egg  | 78       | 6g      |\n"
            . "  ```\n"
            . "- **Bulleted or numbered lists** for steps and options.\n"
            . "- **Headings** (`## Heading`) to group sections of a longer answer (e.g. \"## Breakfast\", \"## Lunch\").\n"
            . "- **Bold** for the key takeaway or a warning, used sparingly.\n"
            . "- **Backticks** for exact numbers or names the user should be able to spot quickly.\n"
            . "Don't use markdown decoratively in casual chat — only when it carries real structure.\n\n"
            . "# Stop rules\n"
            . "- When you've answered, stop. Follow-up questions are optional — only ask one if the input was genuinely ambiguous (e.g. \"is X safe?\" → safe for whom, in what dose). Forcing a question every turn makes the chat feel evasive.\n"
            . "- If the evidence section doesn't support a claim you'd like to make, change the claim or say plainly that you don't have a specific source — don't invent one.\n"
            . "- The user's preferred language is non-negotiable. If you catch yourself drafting in English when the user picked another language, rewrite before sending.";
    }
}
