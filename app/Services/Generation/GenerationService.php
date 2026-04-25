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
        private readonly SystemPromptBuilder $promptBuilder,
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

        // Pull the user attached to the conversation (if any) so the
        // prompt builder can personalise: name + body baseline + goals
        // + safety-relevant medical context. Hotel-vertical sessions
        // are session-auth and have no user — profile stays null and
        // the prompt simply omits the personalisation block.
        $user        = $conversation->user;
        $userProfile = $user?->profile;
        $userName    = $user?->name;

        // Build the system prompt from every admin-configured field so none
        // of the super-admin's persona / scope / rule work gets silently
        // dropped at call time. Shared builder is also used by
        // App\Eval\LiveResolver so chat and eval see the same context.
        $systemPrompt = $this->promptBuilder->build(
            $agent,
            $retrievedContext,
            $userProfile,
            $userName,
        );

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
                model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
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

            // Pass the actual retrieved context so claims can be grounded
            // against evidence the agent saw. Falling back to an empty
            // context only when retrieval itself failed or returned nothing.
            $context = $retrievedContext ?? new \App\Services\Knowledge\RetrievedContext(
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
                        model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
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
