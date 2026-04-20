<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    public function test_chat_sends_store_false_and_returns_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-1',
                'model' => 'gpt-4o-2025-03-15',
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Hello there']],
                ],
                'usage' => [
                    'prompt_tokens' => 5,
                    'completion_tokens' => 2,
                    'total_tokens' => 7,
                ],
            ]),
        ]);

        config(['services.openai.api_key' => 'sk-test', 'services.openai.base_url' => 'https://api.openai.com/v1']);

        $provider = new OpenAiProvider();
        $res = $provider->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
        ));

        $this->assertSame('Hello there', $res->content);
        $this->assertSame('openai', $res->provider);
        $this->assertSame(7, $res->totalTokens);
        $this->assertGreaterThanOrEqual(0, $res->latencyMs);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $body['store'] === false
                && $body['model'] === 'gpt-4o'
                && $body['messages'][0]['content'] === 'Hi';
        });
    }

    public function test_chat_throws_on_http_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response(['error' => ['message' => 'bad']], 400),
        ]);
        config(['services.openai.api_key' => 'sk-test', 'services.openai.base_url' => 'https://api.openai.com/v1']);

        $this->expectException(\RuntimeException::class);
        (new OpenAiProvider())->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
        ));
    }
}
