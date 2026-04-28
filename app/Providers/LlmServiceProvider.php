<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Llm\LlmClient;
use App\Services\Llm\Providers\OpenAiProvider;
use App\Services\Llm\Providers\OpenAiResponsesProvider;
use App\Services\Llm\Providers\ProviderInterface;
use App\Services\Llm\Tracing\LangfuseTracer;
use App\Services\Llm\Tracing\NullTracer;
use App\Services\Llm\Tracing\TracerInterface;
use Illuminate\Support\ServiceProvider;

final class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderInterface::class, function () {
            $provider = config('llm.default_provider', 'openai');
            // Within the openai provider, choose between Chat
            // Completions (legacy) and Responses (preferred for
            // gpt-5.5+) based on env flag. Defaults to `responses`
            // — set LLM_API_BACKEND=chat to roll back to the older
            // surface if Responses regresses anywhere.
            $backend = config('llm.openai_api_backend', env('LLM_API_BACKEND', 'responses'));
            return match ($provider) {
                'openai' => $backend === 'chat'
                    ? new OpenAiProvider()
                    : new OpenAiResponsesProvider(),
                default => throw new \RuntimeException("unknown LLM provider: {$provider}"),
            };
        });

        $this->app->bind(TracerInterface::class, function () {
            return config('services.langfuse.enabled')
                ? new LangfuseTracer()
                : new NullTracer();
        });

        $this->app->bind(LlmClient::class, function ($app) {
            return new LlmClient(
                $app->make(ProviderInterface::class),
                $app->make(TracerInterface::class),
            );
        });
    }
}
