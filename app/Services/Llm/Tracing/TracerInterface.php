<?php

declare(strict_types=1);

namespace App\Services\Llm\Tracing;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;

interface TracerInterface
{
    public function startTrace(LlmRequest $request): string;

    public function recordResponse(string $traceId, LlmRequest $request, LlmResponse $response): void;

    public function recordError(string $traceId, LlmRequest $request, \Throwable $error): void;
}
