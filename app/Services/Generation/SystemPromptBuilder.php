<?php

declare(strict_types=1);

namespace App\Services\Generation;

use App\Models\Agent;
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
    public function build(Agent $agent, ?RetrievedContext $retrieval = null): string
    {
        return $this->compose($agent, $retrieval, /* includeStyle = */ true);
    }

    /**
     * Same as build() but without the JSON-contract block. Use this for
     * eval runs so the model answers in plain prose and string-match
     * assertions have something natural to bite on — wrapping every
     * response in {"reply": "..."} would make assertions match against
     * the JSON wrapper text.
     */
    public function buildForEval(Agent $agent, ?RetrievedContext $retrieval = null): string
    {
        return $this->compose($agent, $retrieval, /* includeStyle = */ false);
    }

    private function compose(Agent $agent, ?RetrievedContext $retrieval, bool $includeStyle): string
    {
        $parts = [];

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

        // 2. Authored instructions (the long-form system prompt).
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
            . "- If the evidence above does not support the claim you want to make, change the claim or say plainly \"I don't have a specific source for this\" rather than inventing one.";
    }

    private function conversationStyleBlock(): string
    {
        return "# Conversation style\n"
            . "Reply SHORT by default — 2 to 4 sentences, like a real chat. Don't dump lists or long explanations unless the user explicitly asks for detail.\n"
            . "If useful, end your reply with ONE natural follow-up question.\n"
            . "Always return a JSON object with this exact shape and nothing else:\n"
            . "{\n  \"reply\": \"your short answer here\",\n  \"suggestions\": [\"short follow-up 1\", \"short follow-up 2\", \"Tell me more\"]\n}\n"
            . "Rules for suggestions:\n"
            . "- 2 to 3 items, each under 50 characters.\n"
            . "- First two are natural next questions the user might want to tap.\n"
            . "- If your reply was short (default), include \"Tell me more\" as the last item so the user can request detail.\n"
            . "- If the user explicitly asked for detail and you gave a longer reply, you may drop \"Tell me more\".\n"
            . "- Red-flag rules and handoffs OVERRIDE normal replies. When a red-flag matches, reply with the exact canned text and set suggestions to a single entry \"I understand\".";
    }
}
