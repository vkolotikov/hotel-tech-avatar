<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;

interface ProviderInterface
{
    public function chat(LlmRequest $request): LlmResponse;

    public function name(): string;
}
