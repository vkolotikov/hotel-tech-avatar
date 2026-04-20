<?php

declare(strict_types=1);

namespace App\Services\Llm;

final class LlmRequest
{
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly float $temperature = 0.3,
        public readonly int $maxTokens = 220,
        public readonly array $tools = [],
        public readonly string $purpose = 'generation',
        public readonly ?int $messageId = null,
        public readonly ?int $parentLlmCallId = null,
    ) {}
}
