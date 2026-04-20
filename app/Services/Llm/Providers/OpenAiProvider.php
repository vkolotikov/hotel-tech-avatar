<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;

final class OpenAiProvider implements ProviderInterface
{
    public function chat(LlmRequest $request): LlmResponse
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.openai.timeout', 45);

        $body = [
            'model' => $request->model,
            'messages' => $request->messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'store' => false,
        ];
        if (!empty($request->tools)) {
            $body['tools'] = $request->tools;
        }

        $start = microtime(true);
        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/chat/completions", $body);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new \RuntimeException("OpenAI chat failed (HTTP {$response->status()})");
        }

        $json = $response->json() ?? [];
        $choice = $json['choices'][0] ?? [];
        $usage = $json['usage'] ?? [];

        return new LlmResponse(
            content: (string) ($choice['message']['content'] ?? ''),
            role: (string) ($choice['message']['role'] ?? 'assistant'),
            provider: 'openai',
            model: (string) ($json['model'] ?? $request->model),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
            latencyMs: $latencyMs,
            traceId: null,
            raw: $json,
        );
    }

    public function name(): string
    {
        return 'openai';
    }
}
