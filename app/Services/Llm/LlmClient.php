<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\LlmCall;
use App\Services\Llm\Providers\ProviderInterface;
use App\Services\Llm\Tracing\TracerInterface;
use Illuminate\Support\Facades\Log;

final class LlmClient
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly TracerInterface $tracer,
    ) {}

    public function chat(LlmRequest $request): LlmResponse
    {
        $traceId = $this->tracer->startTrace($request);

        try {
            $response = $this->provider->chat($request);
        } catch (\Throwable $e) {
            try {
                $this->tracer->recordError($traceId, $request, $e);
                $this->writeLedgerError($request, $traceId, $e);
            } catch (\Throwable $bookkeepingError) {
                Log::warning('LlmClient error bookkeeping failed', [
                    'trace_id' => $traceId,
                    'bookkeeping_error_class' => $bookkeepingError::class,
                ]);
            }
            throw $e;
        }

        $response = new LlmResponse(
            content: $response->content,
            role: $response->role,
            provider: $response->provider,
            model: $response->model,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            totalTokens: $response->totalTokens,
            latencyMs: $response->latencyMs,
            traceId: $traceId,
            raw: $response->raw,
        );

        $this->tracer->recordResponse($traceId, $request, $response);
        $this->writeLedger($request, $response);

        return $response;
    }

    private function writeLedger(LlmRequest $request, LlmResponse $response): void
    {
        if (!config('llm.ledger_enabled', true)) {
            return;
        }

        LlmCall::create([
            'message_id' => $request->messageId,
            'parent_llm_call_id' => $request->parentLlmCallId,
            'purpose' => $request->purpose,
            'provider' => $response->provider,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
            'trace_id' => $response->traceId,
            'metadata' => [
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ],
        ]);
    }

    private function writeLedgerError(LlmRequest $request, string $traceId, \Throwable $e): void
    {
        if (!config('llm.ledger_enabled', true)) {
            return;
        }

        LlmCall::create([
            'message_id' => $request->messageId,
            'parent_llm_call_id' => $request->parentLlmCallId,
            'purpose' => $request->purpose,
            'provider' => $this->provider->name(),
            'model' => $request->model,
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'latency_ms' => null,
            'trace_id' => $traceId,
            'metadata' => [
                'error_class' => $e::class,
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ],
        ]);
    }
}
