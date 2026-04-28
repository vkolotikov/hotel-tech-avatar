<?php

declare(strict_types=1);

namespace App\Services\Llm;

final class LlmRequest
{
    public function __construct(
        /**
         * Conversation messages, oldest first. Each item is
         * `['role' => 'system'|'user'|'assistant', 'content' => string]`.
         * On the Responses API path, the leading system message is
         * lifted into the top-level `instructions` field automatically;
         * everything else lands as `input`.
         */
        public readonly array $messages,
        public readonly string $model,
        public readonly float $temperature = 0.3,
        public readonly int $maxTokens = 220,
        public readonly array $tools = [],
        public readonly string $purpose = 'generation',
        public readonly ?int $messageId = null,
        public readonly ?int $parentLlmCallId = null,
        /**
         * Output shape control.
         * - On the Chat Completions path: `['type' => 'json_object']` or
         *   `['type' => 'json_schema', 'json_schema' => [...]]`.
         * - On the Responses API path: same shape; the provider
         *   translates `json_object` → `{type:'json_object'}` and
         *   `json_schema` → `{type:'json_schema', name, strict, schema}`
         *   under `text.format`.
         * null = free-form text.
         */
        public readonly ?array $responseFormat = null,

        /**
         * Reasoning effort for gpt-5.5 family on the Responses API:
         * 'low' | 'medium' | 'high' | 'xhigh'. Ignored on Chat
         * Completions and on non-reasoning models. Per OpenAI's gpt-5.5
         * guide: 'low' is recommended for chat-style turns; 'medium' is
         * the API default; 'high'/'xhigh' for hard reasoning where
         * latency matters less.
         */
        public readonly ?string $reasoningEffort = null,

        /**
         * Output verbosity for the Responses API: 'low' | 'medium' |
         * 'high'. 'low' produces noticeably more concise responses
         * which is what conversational chat wants. API default is
         * 'medium'. Ignored on Chat Completions.
         */
        public readonly ?string $verbosity = null,
    ) {}
}
