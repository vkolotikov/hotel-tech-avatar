<?php

declare(strict_types=1);

namespace App\Eval;

use App\Models\Agent;
use App\Models\EvalCase;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;

final class LiveResolver
{
    public function __construct(private readonly LlmClient $llmClient) {}

    public function resolve(EvalCase $case, Agent $agent): ResolvedResponse
    {
        // Check red-flag rules first
        $rules = $agent->red_flag_rules_json ?? [];

        foreach ((array) $rules as $rule) {
            $pattern = $rule['pattern_regex'] ?? null;
            if (!$pattern) {
                continue;
            }

            if (@preg_match('/' . $pattern . '/', $case->prompt)) {
                // Match found — return canned response
                $canned = $this->getCannedResponse($agent, $rule['canned_response_key'] ?? null);
                return new ResolvedResponse(
                    text: $canned,
                    red_flag_triggered: true,
                    red_flag_id: $rule['id'] ?? null,
                    handoff_target: $rule['handoff_target'] ?? null,
                );
            }
        }

        // No red-flag match — call the LLM
        $systemPrompt = $agent->activePromptVersion?->system_prompt ?? '';

        $request = new LlmRequest(
            model: config('llm.default_model', 'gpt-4'),
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $case->prompt],
            ],
            temperature: 0.7,
            maxTokens: 1024,
        );

        try {
            $response = $this->llmClient->chat($request);
            return new ResolvedResponse(
                text: $response->content,
                red_flag_triggered: false,
            );
        } catch (\Throwable $e) {
            // Log and return a safe fallback
            \Illuminate\Support\Facades\Log::error('LiveResolver LLM call failed', [
                'case_id' => $case->id,
                'error' => $e->getMessage(),
            ]);
            return new ResolvedResponse(
                text: '[LLM call failed; see logs]',
                red_flag_triggered: false,
            );
        }
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
