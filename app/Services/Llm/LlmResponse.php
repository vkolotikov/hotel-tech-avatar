<?php

declare(strict_types=1);

namespace App\Services\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $role,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly int $latencyMs,
        public readonly ?string $traceId,
        public readonly array $raw = [],
    ) {}
}
