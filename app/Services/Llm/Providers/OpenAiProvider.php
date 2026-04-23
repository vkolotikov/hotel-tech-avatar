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
            'store' => false,
        ];

        // Token-cap parameter depends on the model family:
        //   gpt-5 / gpt-5.4 / o1 / o3 / o4 → max_completion_tokens (required)
        //   gpt-4 / gpt-4o / gpt-3.5       → max_tokens (legacy; others 400)
        if ($this->usesCompletionTokenParam($request->model)) {
            $body['max_completion_tokens'] = $request->maxTokens;
        } else {
            $body['max_tokens'] = $request->maxTokens;
        }

        // Reasoning models (o-series) don't accept temperature overrides —
        // omit the param entirely in that case. GPT-5 family accepts it.
        if (!$this->isReasoningModel($request->model)) {
            $body['temperature'] = $request->temperature;
        }

        if (!empty($request->tools)) {
            $body['tools'] = $request->tools;
        }
        if ($request->responseFormat !== null) {
            $body['response_format'] = $request->responseFormat;
        }

        $start = microtime(true);
        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/chat/completions", $body);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            // Include the response body so upstream catch blocks and logs
            // surface the actual OpenAI reason (model not available, rate
            // limit, invalid param, etc.) rather than a bare HTTP code.
            $body = (string) $response->body();
            $snippet = mb_strlen($body) > 500 ? mb_substr($body, 0, 500) . '…' : $body;
            throw new \RuntimeException(
                "OpenAI chat failed (HTTP {$response->status()}): {$snippet}"
            );
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

    /**
     * True when the given model ID is in a family that uses the newer
     * max_completion_tokens parameter. GPT-5 family (gpt-5, gpt-5.4,
     * gpt-5-mini, gpt-5-nano, etc.) and all reasoning models.
     */
    private function usesCompletionTokenParam(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o1|o3|o4|o5)([.\-]|$)/i', $model);
    }

    /** True for o-series reasoning models, which reject temperature overrides. */
    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(o1|o3|o4|o5)([.\-]|$)/i', $model);
    }
}
