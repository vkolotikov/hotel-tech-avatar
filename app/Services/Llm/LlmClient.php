<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\LlmCall;
use App\Services\Llm\Providers\ProviderInterface;
use App\Services\Llm\Tracing\TracerInterface;
use Illuminate\Support\Facades\Log;

class LlmClient
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
            'metadata' => $this->ledgerMetadata($request),
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
            'metadata' => array_merge(
                ['error_class' => $e::class],
                $this->ledgerMetadata($request),
            ),
        ]);
    }

    /**
     * Knobs we record per call. Includes the new Responses-API tuning
     * (reasoning effort, verbosity) so admin analytics can correlate
     * cost / latency / quality with tuning choices once we start
     * varying them per agent.
     */
    private function ledgerMetadata(LlmRequest $request): array
    {
        $meta = [
            'temperature' => $request->temperature,
            'max_tokens'  => $request->maxTokens,
        ];
        if ($request->reasoningEffort !== null) $meta['reasoning_effort'] = $request->reasoningEffort;
        if ($request->verbosity !== null)        $meta['verbosity']        = $request->verbosity;
        return $meta;
    }
}
