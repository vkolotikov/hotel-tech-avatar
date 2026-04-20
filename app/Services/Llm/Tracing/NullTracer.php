<?php

declare(strict_types=1);

namespace App\Services\Llm\Tracing;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Str;

final class NullTracer implements TracerInterface
{
    public function startTrace(LlmRequest $request): string
    {
        return (string) Str::uuid();
    }

    public function recordResponse(string $traceId, LlmRequest $request, LlmResponse $response): void
    {
        // intentionally empty
    }

    public function recordError(string $traceId, LlmRequest $request, \Throwable $error): void
    {
        // intentionally empty
    }
}
