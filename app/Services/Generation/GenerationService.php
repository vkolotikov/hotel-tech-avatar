<?php

declare(strict_types=1);

namespace App\Services\Generation;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;

final class GenerationService
{
    private const FALLBACK_RESPONSE = 'I recommend consulting a healthcare professional for this question.';
    private const MAX_REVISIONS = 2;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly VerificationServiceInterface $verificationService,
        private readonly \App\Services\Knowledge\RetrievalService $retrieval,
    ) {}

    /**
     * Generate a response for a conversation and optionally verify it.
     *
     * @param Conversation $conversation
     * @return Message|null
     */
    public function generateResponse(Conversation $conversation): ?Message
    {
        $agent = $conversation->agent;

        if (!$agent) {
            throw new \LogicException("Conversation {$conversation->id} has no associated agent.");
        }

        // Ensure vertical relationship is loaded
        if (!$agent->relationLoaded('vertical')) {
            $agent->load('vertical');
        }

        if (empty(config('services.openai.api_key'))) {
            return $conversation->messages()->create([
                'agent_id' => $agent->id,
                'role'     => 'agent',
                'content'  => "I'm currently offline — the AI service is not configured.",
                'trace_id' => null,
            ]);
        }

        $maxContext = (int) config('services.openai.max_context_messages', 20);
        $maxKnowledge = (int) config('services.openai.max_knowledge_chars', 12000);

        // Pull the latest user turn so we can run retrieval for it.
        $latestUserMessage = $conversation->messages()
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->first();

        $retrievedContext = null;
        if ($latestUserMessage && !empty(trim((string) $latestUserMessage->content))) {
            try {
                $retrievedContext = $this->retrieval->retrieve($latestUserMessage->content, $agent);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('GenerationService: retrieval failed, continuing without evidence', [
                    'conversation_id' => $conversation->id,
                    'agent_id'        => $agent->id,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        // Build the system prompt from every admin-configured field so none
        // of the super-admin's persona / scope / rule work gets silently
        // dropped at call time.
        $systemPrompt = $this->buildSystemPrompt($agent, $maxKnowledge, $retrievedContext);

        // Build message history
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit($maxContext)
            ->get()
            ->reverse()
            ->map(fn ($m) => [
                'role'    => $m->role === 'agent' ? 'assistant' : 'user',
                'content' => $m->content,
            ])
            ->values()
            ->toArray();

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history
        );

        // Call LLM
        try {
            $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
                messages: $messages,
                model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.4'),
                temperature: (float) config('services.openai.temperature', 0.3),
                maxTokens: (int) config('services.openai.max_output_tokens', 180),
                tools: [],
                purpose: 'generation',
                messageId: null,
                responseFormat: ['type' => 'json_object'],
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('GenerationService: LLM call failed', [
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return $conversation->messages()->create([
                'agent_id'            => $agent->id,
                'role'                => 'agent',
                'content'             => "I encountered an error generating a response. Please try again.",
                'verification_status' => 'error',
                'trace_id'            => null,
            ]);
        }

        // The model is instructed to return JSON {reply, suggestions}; parse
        // it here and keep the freeform text for downstream verification.
        [$responseText, $suggestions] = $this->parseJsonReply($response->content);

        $verificationLatencyMs = 0;
        $isVerified = null;
        $verificationFailures = null;

        if ($agent->vertical && $agent->vertical->slug === 'wellness') {
            $verificationStartTime = microtime(true);

            // Placeholder: retrieval is wired in Phase 1+.
            // Until then, verification cannot ground claims against retrieved evidence.
            $context = new \App\Services\Knowledge\RetrievedContext(
                chunks: [],
                latency_ms: 0,
                is_high_risk: false,
                chunk_count: 0,
            );

            try {
                $verificationResult = $this->verificationService->verify($responseText, $context, $agent);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('GenerationService: Verification failed', [
                    'conversation_id' => $conversation->id,
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);

                $verificationResult = new \App\Services\Verification\Drivers\VerificationResult(
                    passed: false,
                    chunks: [],
                    latency_ms: 0,
                    is_high_risk: false,
                    chunk_count: 0,
                    failures: [],
                    safety_flags: [],
                    revision_count: 0,
                );
            }

            $revisionCount = 0;
            $messages[] = ['role' => 'assistant', 'content' => $responseText];

            // Revision loop: max 2 attempts, only if there's a suggestion
            while (!$verificationResult->passed && $revisionCount < self::MAX_REVISIONS && $verificationResult->revision_suggestion) {
                $revisionPrompt = $this->buildRevisionPrompt($verificationResult);
                $messages[] = $revisionPrompt;

                try {
                    $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
                        messages: $messages,
                        model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.4'),
                        temperature: (float) config('services.openai.temperature', 0.3),
                        maxTokens: (int) config('services.openai.max_output_tokens', 180),
                        tools: [],
                        purpose: 'generation',
                        messageId: null,
                        responseFormat: ['type' => 'json_object'],
                    ));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('GenerationService: Revision LLM call failed', [
                        'conversation_id' => $conversation->id,
                        'agent_id' => $agent->id,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }

                [$responseText, $suggestions] = $this->parseJsonReply($response->content);
                $messages[] = ['role' => 'assistant', 'content' => $responseText];

                try {
                    $verificationResult = $this->verificationService->verify($responseText, $context, $agent);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('GenerationService: Verification failed during revision', [
                        'conversation_id' => $conversation->id,
                        'agent_id' => $agent->id,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }

                $revisionCount++;
            }

            // Use fallback if verification still failed
            if (!$verificationResult->passed) {
                $responseText = self::FALLBACK_RESPONSE;
            }

            $verificationLatencyMs = (int) round((microtime(true) - $verificationStartTime) * 1000);
            $isVerified = $verificationResult->passed;
            $verificationFailures = !$verificationResult->passed ? $verificationResult->failures : null;
        }

        // Save message
        return $conversation->messages()->create([
            'agent_id'               => $agent->id,
            'role'                   => 'agent',
            'content'                => $responseText,
            'ai_provider'            => $response->provider,
            'ai_model'               => $response->model,
            'prompt_tokens'          => $response->promptTokens,
            'completion_tokens'      => $response->completionTokens,
            'total_tokens'           => $response->totalTokens,
            'ai_latency_ms'          => $response->latencyMs,
            'verification_status'    => match ($agent->vertical->slug) {
                'wellness' => $isVerified ? 'passed' : 'failed',
                default => 'not_required',
            },
            'trace_id'               => $response->traceId,
            'is_verified'            => $isVerified,
            'verification_failures_json' => $verificationFailures,
            'verification_latency_ms' => $verificationLatencyMs > 0 ? $verificationLatencyMs : null,
            'ui_json'                => !empty($suggestions) ? ['suggestions' => $suggestions] : null,
            'retrieval_used'         => $retrievedContext !== null && $retrievedContext->chunk_count > 0,
            'retrieval_source_count' => $retrievedContext?->chunk_count,
        ]);
    }

    /**
     * Parse a JSON-shaped reply ({reply, suggestions}) tolerantly. If the
     * model slipped into free text or markdown-fenced JSON, recover
     * gracefully and return the raw text with no suggestions.
     *
     * @return array{0:string,1:array<int,string>} [replyText, suggestions]
     */
    private function parseJsonReply(string $raw): array
    {
        $trimmed = trim($raw);

        // Strip ``` fences if the model added them.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return [$raw, []];
        }

        $reply = isset($decoded['reply']) && is_string($decoded['reply'])
            ? trim($decoded['reply'])
            : $raw;

        $rawSuggestions = $decoded['suggestions'] ?? [];
        $suggestions = [];
        if (is_array($rawSuggestions)) {
            foreach ($rawSuggestions as $s) {
                if (is_string($s)) {
                    $clean = trim($s);
                    if ($clean !== '') {
                        $suggestions[] = mb_substr($clean, 0, 80);
                    }
                }
                if (count($suggestions) >= 4) break;
            }
        }

        return [$reply === '' ? $raw : $reply, $suggestions];
    }

    /**
     * Build the full system prompt from every admin-configured field.
     *
     * Order is deliberate: identity first (so "what's your name?" always
     * works), then the avatar's authored instructions, then the structured
     * persona / scope / rule sections, then any inline knowledge text,
     * then the global conversation-style contract (JSON, short replies,
     * suggestions).
     */
    private function buildSystemPrompt(
        \App\Models\Agent $agent,
        int $maxKnowledge,
        ?\App\Services\Knowledge\RetrievedContext $retrieval = null,
    ): string {
        $parts = [];

        // 1. Identity — always rendered so basic self-awareness works
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
            $section = $this->renderPersonaSection($agent->persona_json);
            if ($section !== null) {
                $parts[] = $section;
            }
        }

        // 4. Scope guardrails.
        if ($agent->scope_json && is_array($agent->scope_json)) {
            $section = $this->renderScopeSection($agent->scope_json);
            if ($section !== null) {
                $parts[] = $section;
            }
        }

        // 5. Red-flag rules — compiled into a pattern→canned-response list
        //    the model treats as hard overrides.
        if ($agent->red_flag_rules_json && is_array($agent->red_flag_rules_json)) {
            $section = $this->renderRedFlagSection($agent->red_flag_rules_json);
            if ($section !== null) {
                $parts[] = $section;
            }
        }

        // 6. Handoff rules — who to suggest for out-of-scope topics.
        if ($agent->handoff_rules_json && is_array($agent->handoff_rules_json)) {
            $section = $this->renderHandoffSection($agent->handoff_rules_json);
            if ($section !== null) {
                $parts[] = $section;
            }
        }

        // 7. Inline knowledge (short-form, no embeddings needed).
        if (!empty(trim((string) $agent->knowledge_text))) {
            $knowledge = mb_substr($agent->knowledge_text, 0, $maxKnowledge);
            $parts[] = "# Knowledge Base (inline)\n{$knowledge}";
        }

        // 8. Retrieved evidence — chunks from knowledge_chunks via
        //    RetrievalService, grounded on the user's current question.
        if ($retrieval && $retrieval->chunk_count > 0) {
            $section = $this->renderRetrievalSection($retrieval);
            if ($section !== null) {
                $parts[] = $section;
            }
        }

        // 9. Global conversation style + JSON contract. Unconditional.
        $parts[] = $this->conversationStyleBlock();

        return implode("\n\n---\n\n", $parts);
    }

    private function renderPersonaSection(array $persona): ?string
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

    /**
     * Scope may be either the simple admin-UI shape — a list of
     * {topic, response} entries — or the richer seeded shape with
     * in_scope / out_of_scope arrays. Both are rendered.
     */
    private function renderScopeSection(array $scope): ?string
    {
        $lines = [];
        // Simple admin shape: list of {topic, response}.
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

    /**
     * Red-flag rules render as hard overrides. Supports both the simple
     * admin shape ({keywords, response}) and the richer legacy shape
     * ({pattern_regex, category, canned_response_key, …}).
     */
    private function renderRedFlagSection(array $rules): ?string
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

    /**
     * Handoff rules can be either the simple list-of-{trigger,referral}
     * shape (admin UI) or a flat {target: "tags,..."} map (legacy).
     */
    private function renderHandoffSection(array $rules): ?string
    {
        $lines = [];
        $isAssoc = array_keys($rules) !== range(0, count($rules) - 1);
        if ($isAssoc) {
            foreach ($rules as $target => $tags) {
                if (!is_string($tags)) continue;
                $lines[] = "- For topics tagged ({$tags}) → suggest handoff to **{$target}**.";
            }
        } else {
            foreach ($rules as $entry) {
                if (!is_array($entry)) continue;
                $trigger = $entry['trigger'] ?? null;
                $ref     = $entry['referral'] ?? null;
                if ($trigger && $ref) {
                    $lines[] = "- When the user's concern is **{$trigger}**, say: \"" . trim($ref) . "\"";
                }
            }
        }
        return empty($lines) ? null : "# Handoffs\n" . implode("\n", $lines);
    }

    /**
     * Format retrieved chunks as a numbered evidence list the model can
     * cite with (PMID:XXX) / (FDC:XXX) / [n] style markers. Keeps the
     * section within a token budget so the prompt doesn't blow up.
     */
    private function renderRetrievalSection(\App\Services\Knowledge\RetrievedContext $ctx): ?string
    {
        if (empty($ctx->chunks)) return null;

        $lines = [];
        foreach ($ctx->chunks as $i => $chunk) {
            $n = $i + 1;
            $citation = $chunk->citation_key ?: $chunk->source_name;
            $excerpt = mb_substr($chunk->content, 0, 800);
            $lines[] = "[{$n}] ({$citation}) — {$excerpt}\nSource: {$chunk->source_url}";
        }

        return "# Evidence (use for factual claims; cite with (PMID:XXX) or the matching [n] marker)\n"
            . implode("\n\n", $lines)
            . "\n\nWhen making a factual claim, prefer these sources over free-form recall. If nothing matches, say so plainly rather than invent sources.";
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

    private function buildRevisionPrompt(\App\Services\Verification\Drivers\VerificationResult $verificationResult): array
    {
        $failuresSummary = collect($verificationResult->failures)
            ->map(fn ($f) => "{$f->type->name}: {$f->reason}")
            ->join("\n");

        return [
            'role'    => 'user',
            'content' => "The previous response had these issues:\n\n{$failuresSummary}\n\nPlease revise the response to address these concerns. {$verificationResult->revision_suggestion}",
        ];
    }
}
