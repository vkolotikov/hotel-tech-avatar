<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use App\Services\Llm\Tracing\NullTracer;
use PHPUnit\Framework\TestCase;

class NullTracerTest extends TestCase
{
    public function test_null_tracer_returns_trace_id_and_no_ops_record(): void
    {
        $tracer = new NullTracer();
        $req = new LlmRequest(messages: [], model: 'gpt-4o');

        $traceId = $tracer->startTrace($req);
        $this->assertIsString($traceId);
        $this->assertNotSame('', $traceId);

        $tracer->recordResponse($traceId, $req, new LlmResponse(
            content: '', role: 'assistant', provider: 'openai', model: 'gpt-4o',
            promptTokens: 0, completionTokens: 0, totalTokens: 0, latencyMs: 0,
            traceId: $traceId, raw: [],
        ));
        $tracer->recordError($traceId, $req, new \RuntimeException('x'));

        $this->assertTrue(true); // no exceptions = pass
    }
}
