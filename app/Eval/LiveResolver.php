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

        // No red-flag match — call the LLM. Prefer the active prompt version
        // snapshot so evals run against whatever the super-admin blessed;
        // fall back to the agent's live system_instructions when no version
        // has been snapshotted yet.
        $systemPrompt = $agent->activePromptVersion?->system_prompt
            ?? $agent->system_instructions
            ?? "You are {$agent->name}, {$agent->role}. {$agent->description}";

        // Eval runs deliberately allow longer answers than chat (512 vs the
        // 180-token chat default) so string-match assertions have enough
        // text to bite on. One-shot, not a conversation.
        $request = new LlmRequest(
            model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.4'),
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $case->prompt],
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
