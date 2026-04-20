<?php

declare(strict_types=1);

namespace Tests\Unit\Llm;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use App\Services\Llm\Tracing\LangfuseTracer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LangfuseTracerTest extends TestCase
{
    public function test_record_response_posts_trace_and_generation_events(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.public_key' => 'pk-test',
            'services.langfuse.secret_key' => 'sk-test',
            'services.langfuse.host' => 'https://cloud.langfuse.com',
        ]);
        Http::fake([
            'https://cloud.langfuse.com/api/public/ingestion' => Http::response(['status' => 'ok']),
        ]);

        $tracer = new LangfuseTracer();
        $req = new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
            purpose: 'generation',
        );
        $traceId = $tracer->startTrace($req);
        $tracer->recordResponse($traceId, $req, new LlmResponse(
            content: 'Hello', role: 'assistant',
            provider: 'openai', model: 'gpt-4o-2025-03-15',
            promptTokens: 5, completionTokens: 2, totalTokens: 7,
            latencyMs: 140, traceId: $traceId, raw: [],
        ));

        Http::assertSent(function ($request) use ($traceId) {
            $payload = $request->data();
            if (!isset($payload['batch']) || !is_array($payload['batch'])) return false;
            $types = array_map(fn ($e) => $e['type'] ?? null, $payload['batch']);
            return in_array('trace-create', $types, true)
                && in_array('generation-create', $types, true)
                && str_contains(json_encode($payload), $traceId);
        });
    }

    public function test_tracer_is_noop_when_disabled(): void
    {
        config(['services.langfuse.enabled' => false]);
        Http::fake();

        $tracer = new LangfuseTracer();
        $req = new LlmRequest(messages: [], model: 'gpt-4o');
        $traceId = $tracer->startTrace($req);
        $tracer->recordResponse($traceId, $req, new LlmResponse(
            content: '', role: 'assistant', provider: 'openai', model: 'gpt-4o',
            promptTokens: 0, completionTokens: 0, totalTokens: 0, latencyMs: 0,
            traceId: $traceId, raw: [],
        ));

        Http::assertNothingSent();
    }

    public function test_record_error_posts_trace_with_error_metadata(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.public_key' => 'pk',
            'services.langfuse.secret_key' => 'sk',
            'services.langfuse.host' => 'https://cloud.langfuse.com',
        ]);
        Http::fake([
            'https://cloud.langfuse.com/api/public/ingestion' => Http::response(['status' => 'ok']),
        ]);

        $tracer = new LangfuseTracer();
        $req = new LlmRequest(messages: [], model: 'gpt-4o', purpose: 'generation');
        $traceId = $tracer->startTrace($req);
        $tracer->recordError($traceId, $req, new \RuntimeException('OpenAI chat failed (HTTP 500)'));

        Http::assertSent(function ($request) {
            $batch = $request->data()['batch'] ?? [];
            if (count($batch) !== 1) return false;
            $event = $batch[0];
            return ($event['type'] ?? null) === 'trace-create'
                && ($event['body']['metadata']['error_class'] ?? null) === \RuntimeException::class
                && !array_key_exists('error', $event['body']['metadata'] ?? []);
        });
    }

    public function test_tracer_is_noop_when_enabled_but_keys_missing(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.public_key' => '',
            'services.langfuse.secret_key' => '',
            'services.langfuse.host' => 'https://cloud.langfuse.com',
        ]);
        Http::fake();

        $tracer = new LangfuseTracer();
        $req = new LlmRequest(messages: [], model: 'gpt-4o');
        $traceId = $tracer->startTrace($req);
        $tracer->recordResponse($traceId, $req, new LlmResponse(
            content: '', role: 'assistant', provider: 'openai', model: 'gpt-4o',
            promptTokens: 0, completionTokens: 0, totalTokens: 0, latencyMs: 0,
            traceId: $traceId, raw: [],
        ));
        $tracer->recordError($traceId, $req, new \RuntimeException('x'));

        Http::assertNothingSent();
    }

    public function test_tracer_swallows_upstream_errors(): void
    {
        config([
            'services.langfuse.enabled' => true,
            'services.langfuse.public_key' => 'pk',
            'services.langfuse.secret_key' => 'sk',
            'services.langfuse.host' => 'https://cloud.langfuse.com',
        ]);
        Http::fake([
            'https://cloud.langfuse.com/api/public/ingestion' => Http::response('boom', 500),
        ]);

        $tracer = new LangfuseTracer();
        $req = new LlmRequest(messages: [], model: 'gpt-4o');
        $traceId = $tracer->startTrace($req);
        $tracer->recordResponse($traceId, $req, new LlmResponse(
            content: '', role: 'assistant', provider: 'openai', model: 'gpt-4o',
            promptTokens: 0, completionTokens: 0, totalTokens: 0, latencyMs: 0,
            traceId: $traceId, raw: [],
        ));

        $this->assertTrue(true); // must not throw
    }
}
