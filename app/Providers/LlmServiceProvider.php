<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Llm\LlmClient;
use App\Services\Llm\Providers\OpenAiProvider;
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
            return match ($provider) {
                'openai' => new OpenAiProvider(),
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
