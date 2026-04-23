<?php

declare(strict_types=1);

namespace App\Eval;

use App\Models\Agent;
use App\Models\EvalCase;
use App\Services\Generation\SystemPromptBuilder;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;

/**
 * Produces the LLM reply the eval harness scores each case against.
 *
 * Uses the shared SystemPromptBuilder (same module GenerationService
 * uses for chat) so every admin-configured field — identity,
 * instructions, persona, scope, red-flag rules, handoffs — reaches the
 * model in the same form it does at chat time. Eval mode deliberately
 * omits the JSON response-format contract so assertions can match
 * against plain prose.
 *
 * Legacy pattern_regex red-flag rules are short-circuited pre-LLM with
 * a canned response. The simpler {keywords, response} rules the admin
 * UI writes today are rendered into the prompt by the builder, so the
 * model picks them up directly — no short-circuit needed.
 */
final class LiveResolver
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {}

    public function resolve(EvalCase $case, Agent $agent): ResolvedResponse
    {
        // Legacy red-flag short-circuit (pattern_regex shape).
        $regexMatch = $this->matchLegacyRedFlag($agent, $case->prompt);
        if ($regexMatch !== null) {
            return $regexMatch;
        }

        // Full system prompt — identity + instructions + persona + scope +
        // red-flag + handoff + inline knowledge. No JSON wrapper for eval.
        $systemPrompt = $this->promptBuilder->buildForEval($agent);

        // Eval runs allow longer answers than chat so string-match
        // assertions have enough text to bite on. One-shot, not a chat.
        $request = new LlmRequest(
            model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.4'),
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $case->prompt],
            ],
            temperature: (float) config('services.openai.temperature', 0.3),
            maxTokens: 512,
        );

        try {
            $response = $this->llmClient->chat($request);
            return new ResolvedResponse(
                text: $response->content,
                red_flag_triggered: false,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LiveResolver LLM call failed', [
                'case_id' => $case->id,
                'error'   => $e->getMessage(),
            ]);
            return new ResolvedResponse(
                text: '[LLM call failed; see logs]',
                red_flag_triggered: false,
            );
        }
    }

    /**
     * Short-circuit for the legacy red-flag shape
     * ({pattern_regex, canned_response_key, handoff_target, id}). Returns
     * null when no legacy rule matches so the LLM call proceeds.
     */
    private function matchLegacyRedFlag(Agent $agent, string $prompt): ?ResolvedResponse
    {
        $rules = $agent->red_flag_rules_json ?? [];
        foreach ((array) $rules as $rule) {
            if (!is_array($rule)) continue;
            $pattern = $rule['pattern_regex'] ?? null;
            if (!$pattern || !is_string($pattern)) continue;

            if (@preg_match('/' . $pattern . '/', $prompt)) {
                $canned = $this->getCannedResponse($agent, $rule['canned_response_key'] ?? null);
                return new ResolvedResponse(
                    text: $canned,
                    red_flag_triggered: true,
                    red_flag_id: $rule['id'] ?? null,
                    handoff_target: $rule['handoff_target'] ?? null,
                );
            }
        }
        return null;
    }

    private function getCannedResponse(Agent $agent, ?string $key): string
    {
        if (!$key) {
            return '[Canned response key missing]';
        }

        $responses = $agent->activePromptVersion?->canned_responses_json ?? [];
        return $responses[$key] ?? "[Canned response '$key' not found]";
    }
}
