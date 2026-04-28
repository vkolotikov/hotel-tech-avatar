<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls OpenAI's Responses API (POST /v1/responses) — the recommended
 * surface for gpt-5.5 and later. Translates our internal LlmRequest
 * (still shaped around the older Chat Completions message format) into
 * the Responses API's `instructions` + `input` + `text.format` +
 * `reasoning.effort` + `text.verbosity` shape, then maps the response
 * back into our LlmResponse so callers don't need to know which API
 * actually serviced the call.
 *
 * Why a separate provider rather than branching inside OpenAiProvider:
 * the request and response shapes are different enough that branching
 * would obscure both paths. Keeping them in distinct classes makes
 * each provider trivial to reason about and lets us roll back with a
 * single env flag flip if Responses regresses anywhere.
 *
 * Reference: docs/integrations/openai-gpt-5.5-notes.md and the official
 * OpenAI migration guide.
 */
final class OpenAiResponsesProvider implements ProviderInterface
{
    public function chat(LlmRequest $request): LlmResponse
    {
        $apiKey  = (string) config('services.openai.api_key', '');
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.openai.timeout', 60);

        // Split off the leading system message into `instructions` —
        // Responses keeps the top-level system separate from the
        // turn-by-turn input items. Keeps prompt caching effective:
        // instructions stay stable across turns while input rotates.
        $instructions = null;
        $input = [];
        foreach ($request->messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = (string) ($msg['content'] ?? '');
            if ($role === 'system' && $instructions === null) {
                $instructions = $content;
                continue;
            }
            // Responses input items map cleanly from chat-completions
            // shape — same role keys (user / assistant), content as a
            // string. The API also accepts content as an array of
            // content parts but we don't need that for plain text.
            $input[] = [
                'role'    => $role === 'system' ? 'user' : $role,
                'content' => $content,
            ];
        }

        $body = [
            'model'             => $request->model,
            'input'             => $input,
            // store=false matches the existing OpenAiProvider's belt-
            // and-braces ZDR posture. Without it OpenAI persists the
            // response on their side for 30 days, which we don't want
            // for health data even with org-level retention disabled.
            'store'             => false,
            'max_output_tokens' => $request->maxTokens,
        ];

        if ($instructions !== null && $instructions !== '') {
            $body['instructions'] = $instructions;
        }

        // Reasoning effort applies to gpt-5/o-series. Sending it to a
        // model that doesn't support reasoning returns 400, so gate
        // on the model id first.
        if ($request->reasoningEffort !== null && $this->supportsReasoning($request->model)) {
            $body['reasoning'] = ['effort' => $request->reasoningEffort];
        }

        // Verbosity is part of the `text` object alongside `format`.
        // Both are optional. Build the text object only if either is
        // set, so we don't send `{"text": {}}` for plain text-output
        // turns.
        $text = [];
        if ($request->verbosity !== null) {
            $text['verbosity'] = $request->verbosity;
        }
        if ($request->responseFormat !== null) {
            $text['format'] = $this->translateResponseFormat($request->responseFormat);
        }
        if (!empty($text)) {
            $body['text'] = $text;
        }

        // Reasoning models on Responses don't take a top-level
        // temperature override — it lives implicitly inside the
        // reasoning policy. Send temperature only for non-reasoning
        // models (kept for backwards compat if we route, say, gpt-4o
        // through this provider too).
        if (!$this->supportsReasoning($request->model)) {
            $body['temperature'] = $request->temperature;
        }

        if (!empty($request->tools)) {
            $body['tools'] = $request->tools;
        }

        $start    = microtime(true);
        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/responses", $body);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            $rawBody = (string) $response->body();
            Log::error('OpenAI Responses API failed', [
                'status'      => $response->status(),
                'model'       => $request->model,
                'purpose'     => $request->purpose,
                'effort'      => $request->reasoningEffort,
                'verbosity'   => $request->verbosity,
                'body'        => $rawBody,
            ]);
            $snippet = mb_strlen($rawBody) > 500 ? mb_substr($rawBody, 0, 500) . '…' : $rawBody;
            throw new \RuntimeException(
                "OpenAI Responses failed (HTTP {$response->status()}, model={$request->model}): {$snippet}"
            );
        }

        $json = $response->json() ?? [];

        // Prefer the API's pre-aggregated `output_text` helper. Fall
        // back to walking the `output` array and concatenating every
        // `output_text` content block from `message`-type items —
        // covers the case where the helper field is missing on some
        // server versions.
        $content = $this->extractText($json);
        $usage   = $json['usage'] ?? [];

        return new LlmResponse(
            content: $content,
            // Responses API doesn't echo a role on the message; we
            // synthesise 'assistant' so downstream code matches the
            // Chat Completions LlmResponse shape exactly.
            role: 'assistant',
            provider: 'openai',
            model: (string) ($json['model'] ?? $request->model),
            // Responses returns input_tokens / output_tokens; map to
            // our prompt_tokens / completion_tokens names so the
            // ledger stays consistent across providers.
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? (
                ((int) ($usage['input_tokens'] ?? 0))
                + ((int) ($usage['output_tokens'] ?? 0))
            )),
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
     * Translate our generic responseFormat shape to the Responses API
     * `text.format` shape:
     *   {type: json_object}  → {type: json_object}                 (passthrough)
     *   {type: json_schema, json_schema: {...}}
     *                        → {type: json_schema, name, strict, schema}
     * Anything else → passed through unchanged so future format types
     * (e.g. `text` plain) work without code changes.
     */
    private function translateResponseFormat(array $rf): array
    {
        $type = $rf['type'] ?? null;
        if ($type === 'json_schema' && isset($rf['json_schema']) && is_array($rf['json_schema'])) {
            $sch = $rf['json_schema'];
            return [
                'type'   => 'json_schema',
                'name'   => (string) ($sch['name'] ?? 'response'),
                'strict' => (bool) ($sch['strict'] ?? true),
                'schema' => $sch['schema'] ?? [],
            ];
        }
        return $rf;
    }

    /**
     * Walk the `output` array and concatenate text from every message
     * item's content blocks of type `output_text`. Used when the
     * top-level `output_text` helper isn't present.
     */
    private function extractText(array $json): string
    {
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }
        $parts = [];
        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') continue;
            foreach ($item['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'output_text' && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
        }
        return implode('', $parts);
    }

    /**
     * True when the model supports the `reasoning.effort` parameter.
     * Currently: GPT-5 family + o-series. gpt-4o and earlier do not.
     */
    private function supportsReasoning(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o1|o3|o4|o5)([.\-]|$)/i', $model);
    }
}
