<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\Providers\OpenAiResponsesProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiResponsesProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.openai.api_key'  => 'sk-test',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    public function test_chat_lifts_system_message_into_instructions_and_returns_text(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id'          => 'resp_1',
                'model'       => 'gpt-5.5-2026-04-15',
                'output_text' => 'Hello there',
                'usage'       => [
                    'input_tokens'  => 9,
                    'output_tokens' => 3,
                    'total_tokens'  => 12,
                ],
            ]),
        ]);

        $res = (new OpenAiResponsesProvider())->chat(new LlmRequest(
            messages: [
                ['role' => 'system', 'content' => 'Be concise.'],
                ['role' => 'user',   'content' => 'Hi'],
            ],
            model: 'gpt-5.5',
            reasoningEffort: 'low',
            verbosity: 'low',
        ));

        $this->assertSame('Hello there', $res->content);
        $this->assertSame('assistant', $res->role);
        $this->assertSame('openai', $res->provider);
        $this->assertSame(9, $res->promptTokens);
        $this->assertSame(3, $res->completionTokens);
        $this->assertSame(12, $res->totalTokens);

        Http::assertSent(function ($request) {
            $body = $request->data();
            // System lifted to instructions; only user remains as input.
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $body['model'] === 'gpt-5.5'
                && $body['instructions'] === 'Be concise.'
                && count($body['input']) === 1
                && $body['input'][0]['role'] === 'user'
                && $body['input'][0]['content'] === 'Hi'
                && $body['store'] === false
                && $body['reasoning']['effort'] === 'low'
                && $body['text']['verbosity'] === 'low';
        });
    }

    public function test_chat_falls_back_to_walking_output_when_helper_missing(): void
    {
        // Some server versions omit the `output_text` helper. Provider
        // should walk `output[].content[]` and concatenate every
        // `output_text` block — verify both blocks land in the final text.
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id'    => 'resp_2',
                'model' => 'gpt-5.5',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'Part one. '],
                            ['type' => 'output_text', 'text' => 'Part two.'],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 4, 'output_tokens' => 5, 'total_tokens' => 9],
            ]),
        ]);

        $res = (new OpenAiResponsesProvider())->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-5.5',
        ));

        $this->assertSame('Part one. Part two.', $res->content);
    }

    public function test_chat_skips_reasoning_for_non_reasoning_models(): void
    {
        // gpt-4o doesn't support reasoning.effort; sending it returns 400.
        // The provider should silently drop it — and instead include
        // temperature, which non-reasoning models do accept.
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => 'ok',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        (new OpenAiResponsesProvider())->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-4o',
            temperature: 0.42,
            reasoningEffort: 'high', // ← should be dropped
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();
            return !isset($body['reasoning'])
                && ($body['temperature'] ?? null) === 0.42;
        });
    }

    public function test_chat_translates_json_schema_response_format(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => '{"reply":"hi","suggestions":[]}',
                'usage'       => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);

        (new OpenAiResponsesProvider())->chat(new LlmRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            model: 'gpt-5.5',
            responseFormat: [
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => 'wellness_reply',
                    'strict' => true,
                    'schema' => ['type' => 'object'],
                ],
            ],
        ));

        Http::assertSent(function ($request) {
            $fmt = $request->data()['text']['format'] ?? null;
            return $fmt
                && $fmt['type'] === 'json_schema'
                && $fmt['name'] === 'wellness_reply'
                && $fmt['strict'] === true
                && is_array($fmt['schema']);
        });
    }

    public function test_chat_throws_with_body_snippet_on_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                ['error' => ['message' => 'invalid_model']], 400
            ),
        ]);

        try {
            (new OpenAiResponsesProvider())->chat(new LlmRequest(
                messages: [['role' => 'user', 'content' => 'Hi']],
                model: 'gpt-5.5',
            ));
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('model=gpt-5.5', $e->getMessage());
            $this->assertStringContainsString('invalid_model', $e->getMessage());
        }
    }
}
