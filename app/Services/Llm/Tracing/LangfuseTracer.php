<?php

declare(strict_types=1);

namespace App\Services\Llm\Tracing;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class LangfuseTracer implements TracerInterface
{
    public function startTrace(LlmRequest $request): string
    {
        return (string) Str::uuid();
    }

    public function recordResponse(string $traceId, LlmRequest $request, LlmResponse $response): void
    {
        if (!$this->enabled()) return;

        $now = now()->toIso8601String();
        $batch = [
            [
                'id' => (string) Str::uuid(),
                'type' => 'trace-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $traceId,
                    'name' => $request->purpose,
                    'timestamp' => $now,
                ],
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'generation-create',
                'timestamp' => $now,
                'body' => [
                    'id' => (string) Str::uuid(),
                    'traceId' => $traceId,
                    'name' => $request->purpose,
                    'model' => $response->model,
                    'modelParameters' => [
                        'temperature' => $request->temperature,
                        'maxTokens' => $request->maxTokens,
                    ],
                    'input' => $request->messages,
                    'output' => ['role' => $response->role, 'content' => $response->content],
                    'usage' => [
                        'input' => $response->promptTokens,
                        'output' => $response->completionTokens,
                        'total' => $response->totalTokens,
                    ],
                    'startTime' => $now,
                    'endTime' => $now,
                ],
            ],
        ];
        $this->send($batch);
    }

    public function recordError(string $traceId, LlmRequest $request, \Throwable $error): void
    {
        if (!$this->enabled()) return;

        $now = now()->toIso8601String();
        $batch = [
            [
                'id' => (string) Str::uuid(),
                'type' => 'trace-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $traceId,
                    'name' => $request->purpose,
                    'timestamp' => $now,
                    'metadata' => ['error' => $error->getMessage()],
                ],
            ],
        ];
        $this->send($batch);
    }

    private function enabled(): bool
    {
        return (bool) config('services.langfuse.enabled')
            && (string) config('services.langfuse.public_key', '') !== ''
            && (string) config('services.langfuse.secret_key', '') !== '';
    }

    private function send(array $batch): void
    {
        try {
            $host = rtrim((string) config('services.langfuse.host', 'https://cloud.langfuse.com'), '/');
            Http::withBasicAuth(
                    (string) config('services.langfuse.public_key'),
                    (string) config('services.langfuse.secret_key'),
                )
                ->timeout((int) config('services.langfuse.timeout', 5))
                ->asJson()
                ->post("{$host}/api/public/ingestion", ['batch' => $batch])
                ->throw();
        } catch (\Throwable $e) {
            Log::warning('Langfuse ingestion failed', ['error' => $e->getMessage()]);
        }
    }
}
