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

        // Build system prompt
        $systemPrompt = $agent->system_instructions ?? "You are {$agent->name}, {$agent->role}. {$agent->description}";

        if ($agent->knowledge_text) {
            $knowledge = mb_substr($agent->knowledge_text, 0, $maxKnowledge);
            $systemPrompt .= "\n\n--- Knowledge Base ---\n{$knowledge}";
        }

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
                maxTokens: (int) config('services.openai.max_output_tokens', 220),
                tools: [],
                purpose: 'generation',
                messageId: null,
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

        // Conditional verification with revision loop
        $responseText = $response->content;
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
                        model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
                        temperature: (float) config('services.openai.temperature', 0.3),
                        maxTokens: (int) config('services.openai.max_output_tokens', 220),
                        tools: [],
                        purpose: 'generation',
                        messageId: null,
                    ));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('GenerationService: Revision LLM call failed', [
                        'conversation_id' => $conversation->id,
                        'agent_id' => $agent->id,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }

                $responseText = $response->content;
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
        ]);
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
