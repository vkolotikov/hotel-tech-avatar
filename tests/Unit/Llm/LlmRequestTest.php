<?php

declare(strict_types=1);

namespace Tests\Unit\Llm;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use PHPUnit\Framework\TestCase;

class LlmRequestTest extends TestCase
{
    public function test_request_exposes_required_fields(): void
    {
        $req = new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
            temperature: 0.3,
            maxTokens: 220,
            tools: [],
            purpose: 'generation',
            messageId: 42,
            parentLlmCallId: null,
        );

        $this->assertSame('gpt-4o', $req->model);
        $this->assertSame(220, $req->maxTokens);
        $this->assertSame('generation', $req->purpose);
        $this->assertSame(42, $req->messageId);
    }

    public function test_response_exposes_usage_and_latency(): void
    {
        $res = new LlmResponse(
            content: 'Hello',
            role: 'assistant',
            provider: 'openai',
            model: 'gpt-4o-2025-03-15',
            promptTokens: 10,
            completionTokens: 2,
            totalTokens: 12,
            latencyMs: 314,
            traceId: 'trace_abc',
            raw: ['id' => 'chatcmpl-xyz'],
        );

        $this->assertSame('Hello', $res->content);
        $this->assertSame(12, $res->totalTokens);
        $this->assertSame('trace_abc', $res->traceId);
    }

    public function test_response_allows_null_trace_id(): void
    {
        $res = new LlmResponse(
            content: 'Hi',
            role: 'assistant',
            provider: 'openai',
            model: 'gpt-4o-2025-03-15',
            promptTokens: 3,
            completionTokens: 1,
            totalTokens: 4,
            latencyMs: 120,
            traceId: null,
        );

        $this->assertNull($res->traceId);
        $this->assertSame([], $res->raw);
    }
}
