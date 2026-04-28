<?php

declare(strict_types=1);

namespace App\Services\Generation;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;

final class GenerationService
{
    private const MAX_REVISIONS = 2;

    /**
     * Localised fallback / error / offline strings. Keys match the
     * preferred_language column on user_profiles. Anything outside
     * this set falls through to English. KEEP THESE IN SYNC with the
     * app's supported_languages list — adding a 10th language means
     * adding a row here too, otherwise that user gets English fallbacks
     * even though the rest of the chat is in their language.
     */
    private const FALLBACK_MESSAGES = [
        'en' => "I'd suggest checking in with a healthcare professional on this one.",
        'es' => "Te sugiero consultarlo con un profesional de la salud.",
        'fr' => "Je vous suggère d'en parler à un professionnel de santé.",
        'de' => "Ich würde empfehlen, das mit einer Fachperson zu besprechen.",
        'pl' => "Zalecam skonsultować to z pracownikiem służby zdrowia.",
        'it' => "Ti suggerisco di parlarne con un professionista sanitario.",
        'ru' => "Рекомендую обсудить это с медицинским специалистом.",
        'uk' => "Рекомендую обговорити це з медичним фахівцем.",
        'lv' => "Iesaku par to konsultēties ar veselības aprūpes speciālistu.",
    ];

    private const ERROR_MESSAGES = [
        'en' => "Something went wrong on my end. Please try again.",
        'es' => "Algo falló de mi lado. Vuelve a intentarlo.",
        'fr' => "Un problème est survenu de mon côté. Réessayez.",
        'de' => "Auf meiner Seite ist etwas schiefgelaufen. Bitte erneut versuchen.",
        'pl' => "Coś poszło nie tak po mojej stronie. Spróbuj ponownie.",
        'it' => "Qualcosa è andato storto. Riprova.",
        'ru' => "Что-то пошло не так. Попробуйте снова.",
        'uk' => "Щось пішло не так. Спробуйте знову.",
        'lv' => "Manā pusē kaut kas neizdevās. Lūdzu, mēģini vēlreiz.",
    ];

    private const OFFLINE_MESSAGES = [
        'en' => "I'm currently offline — please try again shortly.",
        'es' => "Estoy desconectado en este momento — inténtalo en un momento.",
        'fr' => "Je suis hors ligne pour le moment — réessayez dans un instant.",
        'de' => "Ich bin gerade offline — bitte versuche es gleich erneut.",
        'pl' => "Jestem teraz offline — spróbuj ponownie za chwilę.",
        'it' => "Sono offline in questo momento — riprova tra poco.",
        'ru' => "Я сейчас офлайн — попробуйте через минуту.",
        'uk' => "Я зараз офлайн — спробуйте за хвилину.",
        'lv' => "Pašlaik esmu bezsaistē — lūdzu, mēģini vēlāk.",
    ];

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly VerificationServiceInterface $verificationService,
        private readonly \App\Services\Knowledge\RetrievalService $retrieval,
        private readonly SystemPromptBuilder $promptBuilder,
    ) {}

    /**
     * Pick the localised message matching the conversation user's
     * preferred_language, falling back to English. Centralised so
     * adding a new error type doesn't mean writing the same lookup
     * three times.
     */
    private function localized(array $bag, ?string $lang): string
    {
        if ($lang && isset($bag[$lang])) return $bag[$lang];
        return $bag['en'];
    }

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

        // User's preferred language drives every fallback message
        // below so a Russian/Polish/Latvian user never sees a sudden
        // English error mid-conversation.
        $userLang = $conversation->user?->profile?->preferred_language;

        if (empty(config('services.openai.api_key'))) {
            return $conversation->messages()->create([
                'agent_id' => $agent->id,
                'role'     => 'agent',
                'content'  => $this->localized(self::OFFLINE_MESSAGES, $userLang),
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

        // Call LLM. The strict JSON schema below replaces the older
        // "describe the JSON shape in the prompt" pattern — gpt-5.5
        // Structured Outputs validate the response server-side, which
        // both improves adherence and lets us drop the schema text from
        // the prompt itself (per the gpt-5.5 prompting guide).
        $reasoningEffort = $agent->reasoning_effort
            ?? (string) config('services.openai.reasoning_effort', 'low');
        $verbosity = $agent->verbosity
            ?? (string) config('services.openai.verbosity', 'low');

        try {
            $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
                messages: $messages,
                model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.5'),
                temperature: (float) config('services.openai.temperature', 0.3),
                maxTokens: (int) config('services.openai.max_output_tokens', 1500),
                tools: [],
                purpose: 'generation',
                messageId: null,
                responseFormat: $this->wellnessReplyJsonSchema(),
                reasoningEffort: $reasoningEffort,
                verbosity: $verbosity,
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
                'content'             => $this->localized(self::ERROR_MESSAGES, $userLang),
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
                        model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-5.5'),
                        temperature: (float) config('services.openai.temperature', 0.3),
                        maxTokens: (int) config('services.openai.max_output_tokens', 1500),
                        tools: [],
                        purpose: 'generation',
                        messageId: null,
                        responseFormat: $this->wellnessReplyJsonSchema(),
                        reasoningEffort: $reasoningEffort,
                        verbosity: $verbosity,
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

            // If verification still rejects after revisions, only swap
            // in the localised fallback when the failures include a
            // hard-safety violation. For softer failures (citation
            // formatting nits, completeness gripes from the critic)
            // keep the model's last attempt — it's almost certainly
            // useful even if not perfectly grounded, and the
            // generic "consult a professional" deflection feels
            // worse than a slightly imperfect answer.
            if (!$verificationResult->passed) {
                $hasHardFailure = false;
                foreach ($verificationResult->failures as $f) {
                    $type = $f->type->value ?? null;
                    if ($type === 'safety_violation') {
                        $hasHardFailure = true;
                        break;
                    }
                }
                if ($hasHardFailure) {
                    $responseText = $this->localized(self::FALLBACK_MESSAGES, $userLang);
                }
                // else: keep $responseText as-is (the model's last try).
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
     * Strict JSON schema for the wellness reply contract. With Structured
     * Outputs (Responses API `text.format` / Chat Completions
     * `response_format`), the model is constrained server-side to emit
     * exactly this shape, so we no longer need to describe it in the
     * system prompt. `strict: true` requires every property be listed in
     * `required` and `additionalProperties: false` — that's the
     * OpenAI-side contract for guaranteed schema adherence.
     *
     * Shape:
     *   - reply        : the user-visible answer text, in the user's
     *                    preferred language (system prompt enforces lang).
     *   - suggestions  : up to 3 short follow-up prompts (≤80 chars each)
     *                    rendered as quick-reply chips. Empty array is
     *                    fine — strict mode requires the key to exist
     *                    but the array can be empty.
     */
    private function wellnessReplyJsonSchema(): array
    {
        return [
            'type'        => 'json_schema',
            'json_schema' => [
                'name'   => 'wellness_reply',
                'strict' => true,
                'schema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['reply', 'suggestions'],
                    'properties'           => [
                        'reply' => [
                            'type'        => 'string',
                            'description' => "The user-visible answer in the user's preferred language. Plain prose, no JSON, no markdown fences.",
                        ],
                        'suggestions' => [
                            'type'        => 'array',
                            'description' => 'Up to 3 short follow-up prompts (≤80 chars) the user can tap. Empty array if nothing useful comes to mind.',
                            'maxItems'    => 3,
                            'items'       => [
                                'type'      => 'string',
                                'maxLength' => 80,
                            ],
                        ],
                    ],
                ],
            ],
        ];
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
