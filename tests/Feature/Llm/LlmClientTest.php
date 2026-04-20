<?php

declare(strict_types=1);

namespace Tests\Feature\Llm;

use App\Models\LlmCall;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_writes_llm_calls_row_and_returns_response_with_trace_id(): void
    {
        config([
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.langfuse.enabled' => false,
        ]);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'model' => 'gpt-4o-2025-03-15',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi back']]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6],
            ]),
        ]);

        /** @var LlmClient $client */
        $client = app(LlmClient::class);
        $res = $client->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
            purpose: 'generation',
        ));

        $this->assertSame('Hi back', $res->content);
        $this->assertNotNull($res->traceId);

        $row = LlmCall::firstOrFail();
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-4o-2025-03-15', $row->model);
        $this->assertSame(4, $row->prompt_tokens);
        $this->assertSame(2, $row->completion_tokens);
        $this->assertSame($res->traceId, $row->trace_id);
        $this->assertSame('generation', $row->purpose);
    }

    public function test_chat_records_error_and_rethrows(): void
    {
        config([
            'services.openai.api_key' => 'sk-test',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.langfuse.enabled' => false,
        ]);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(['error' => 'nope'], 500),
        ]);

        $client = app(LlmClient::class);
        try {
            $client->chat(new LlmRequest(
                messages: [['role' => 'user', 'content' => 'Hi']],
                model: 'gpt-4o',
            ));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        // Ledger row written even on failure, with null tokens.
        $row = LlmCall::firstOrFail();
        $this->assertSame('openai', $row->provider);
        $this->assertNull($row->prompt_tokens);
        $this->assertSame(\RuntimeException::class, $row->metadata['error_class'] ?? null);
        // Raw exception message must never land in the ledger — it can carry
        // user content from future providers and is persisted/observable.
        $this->assertArrayNotHasKey('error', $row->metadata);
    }
}
