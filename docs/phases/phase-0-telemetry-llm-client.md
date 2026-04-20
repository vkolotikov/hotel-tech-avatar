# Phase 0 — Telemetry + LlmClient Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a single `LlmClient` abstraction that every LLM generation call flows through, with Langfuse tracing, Sentry error tracking, and persistence to the existing `llm_calls` ledger. Migrate the existing hotel chat flow to use it. No regressions in hotel SPA.

**Architecture:** A thin provider-agnostic `LlmClient::chat(LlmRequest): LlmResponse` wraps an `OpenAiProvider` (only provider in Phase 0). Each call is timed, traced (Langfuse via plain HTTP — no SDK), ledgered (`llm_calls` row with `trace_id`), and enforces `store=false` against OpenAI unconditionally. Sentry is wired backend-only via `sentry/sentry-laravel`; mobile Sentry is deferred to the Expo skeleton plan. Existing `OpenAiService::chat()` is removed once `ConversationController` migrates; STT/TTS/vector-store methods stay in `OpenAiService` (not the "LLM generation" gate that matters for observability).

**Tech Stack:** Laravel 13 / PHP 8.4, `sentry/sentry-laravel` (Composer), `Illuminate\Support\Facades\Http` for Langfuse ingestion, Pest/PHPUnit, `Http::fake()` for provider/tracer unit tests.

---

## Part A — Scope boundaries (read before starting)

**In scope:**
- `app/Services/Llm/` directory: `LlmClient`, `LlmRequest`, `LlmResponse`, `Providers/ProviderInterface`, `Providers/OpenAiProvider`, `Tracing/TracerInterface`, `Tracing/LangfuseTracer`, `Tracing/NullTracer`.
- `App\Models\LlmCall` Eloquent model (table already exists from `2026_04_19_000017_create_llm_calls.php`).
- `config/llm.php` + additions to `config/services.php`.
- Sentry install via `composer require sentry/sentry-laravel`; before-send scrubber registered in `bootstrap/app.php`.
- Migration of `ConversationController::*` chat call site (line ~231) from `OpenAiService::chat()` to `LlmClient::chat()`.
- Unit tests for every new class; one feature test verifying the full chat path writes a `llm_calls` row + sends a Langfuse envelope (with `Http::fake`).
- ADR recording decisions.
- Integration docs updated (`docs/integrations/{sentry,langfuse,openai}.md`).

**Out of scope (do not add):**
- Multi-provider (Anthropic, Google) adapters — `ProviderInterface` is designed for them but no implementations in this plan.
- STT/TTS/files/vector-store migration — they remain in `OpenAiService`.
- Streaming responses. Current `OpenAiService::chat()` is blocking and so is the LlmClient. SSE stays at the controller layer around the returned response.
- Mobile Sentry — deferred to the Expo skeleton plan.
- Retry/backoff logic. Classifying transient vs rate-limit vs content-policy errors is noted as a Phase 1 follow-up in the ADR.
- PostHog, cost enforcement, per-user quota — those are Phase 1+.

**CLAUDE.md rules that bind this plan:**
- §"Hard rules" #2 — every factual claim must carry a citation (not enforced at LlmClient layer — that's verification pipeline, Phase 1+). LlmClient only logs what happened.
- §"Hard rules" #5 — no user health content to a provider without ZDR. `store=false` on every call is the belt; Wellness content is not in this phase anyway.
- §"Existing in-flight work" — Hotel SPA must not regress. `HotelSpaRegressionTest` stays green, manual SPA smoke passes.
- §"API integration rules" — any new third-party integration must have its `docs/integrations/` file updated after it works.

---

## Part B — Tasks

### Task C.0 — Plan review

**Files:** (none — reading only)

- [ ] **Step 1: Read required context**

Read in full:
- `CLAUDE.md` (root)
- `docs/PROJECT_SPEC.md` §6 (orchestration and generation), §9 (cost + observability), §10 (safety rules)
- `docs/integrations/openai.md`, `docs/integrations/langfuse.md`, `docs/integrations/sentry.md`
- `docs/compliance/openai-zdr.md`
- `app/Services/OpenAiService.php` in full
- `app/Http/Controllers/Api/V1/ConversationController.php` around line 200–260
- `database/migrations/2026_04_19_000017_create_llm_calls.php`

- [ ] **Step 2: Fetch live docs**

- Sentry Laravel: `https://docs.sentry.io/platforms/php/guides/laravel/`
- Langfuse ingestion API: `https://langfuse.com/docs/api` (POST `/api/public/ingestion`)
- OpenAI chat completions (`store` param): `https://platform.openai.com/docs/api-reference/chat/create`

- [ ] **Step 3: Confirm no-op**

No files change. Proceed to C.1.

---

### Task C.1 — Install sentry/sentry-laravel

**Files:**
- Modify: `composer.json`
- Create: `config/sentry.php` (published by installer)
- Modify: `bootstrap/app.php`
- Modify: `.env.example`

- [ ] **Step 1: Install the package**

```bash
composer require sentry/sentry-laravel
```

Expected: package added, `composer.lock` updated.

- [ ] **Step 2: Publish config**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan sentry:publish --dsn=https://dummy@dummy.ingest.sentry.io/1
```

This creates `config/sentry.php` and appends `SENTRY_LARAVEL_DSN` + `SENTRY_TRACES_SAMPLE_RATE` stubs to `.env`. Revert any `.env` changes — we only want `.env.example` updated.

- [ ] **Step 3: Add to `.env.example`**

Append at the bottom of `.env.example`:

```
# Sentry (backend)
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
```

- [ ] **Step 4: Wire the before-send scrubber**

Edit `bootstrap/app.php`. Import `Sentry\Event` at top, then register inside the existing `->withExceptions(...)` closure:

```php
use Sentry\Event;
use Sentry\EventHint;

// Inside withExceptions closure, ADD (don't replace existing render block):
\Sentry\Laravel\Integration::handles($exceptions);
```

Then in `config/sentry.php`, set the `before_send` closure:

```php
'before_send' => function (\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event {
    // PHI scrubbing — message bodies and prompt/response strings never leave the host.
    // Walks nested structures because real payloads are often wrapped
    // (e.g. {"conversation": {"message": "..."}}).
    $scrubKeys = ['message', 'prompt', 'response', 'content', 'transcription', 'input', 'output'];

    $scrub = function (&$node) use (&$scrub, $scrubKeys): void {
        if (!is_array($node)) return;
        foreach ($node as $k => &$v) {
            if (in_array($k, $scrubKeys, true)) { $v = '[scrubbed]'; continue; }
            if (is_array($v)) $scrub($v);
        }
    };

    $request = $event->getRequest();
    if (is_array($request) && isset($request['data']) && is_array($request['data'])) {
        $scrub($request['data']);
        $event->setRequest($request);
    }
    return $event;
},
```

- [ ] **Step 5: Smoke-test Sentry capture locally**

With `SENTRY_LARAVEL_DSN` unset, Sentry is a no-op. Write a throwaway route `routes/web.php` (add then remove in next commit):

```php
Route::get('/__sentry-test', fn () => throw new \RuntimeException('sentry smoke'));
```

Visit `http://avatar.local/__sentry-test`. Expected: Laravel's exception page renders; no errors about Sentry itself. Log line in `storage/logs/laravel.log` shows the exception (Sentry is silent because DSN empty).

Remove the test route before committing.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/sentry.php bootstrap/app.php .env.example
git commit -m "feat(observability): install sentry/sentry-laravel with PHI scrubber"
```

---

### Task C.2 — LlmRequest and LlmResponse DTOs

**Files:**
- Create: `app/Services/Llm/LlmRequest.php`
- Create: `app/Services/Llm/LlmResponse.php`
- Create: `tests/Unit/Llm/LlmRequestTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Llm/LlmRequestTest.php`:

```php
<?php

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
}
```

- [ ] **Step 2: Run tests — expect fail**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=LlmRequestTest
```

Expected: FAIL — classes don't exist.

- [ ] **Step 3: Implement `LlmRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm;

final class LlmRequest
{
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly float $temperature = 0.3,
        public readonly int $maxTokens = 220,
        public readonly array $tools = [],
        public readonly string $purpose = 'generation',
        public readonly ?int $messageId = null,
        public readonly ?int $parentLlmCallId = null,
    ) {}
}
```

- [ ] **Step 4: Implement `LlmResponse.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $role,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly int $latencyMs,
        public readonly ?string $traceId,
        public readonly array $raw = [],
    ) {}
}
```

- [ ] **Step 5: Run tests — expect green**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=LlmRequestTest
```

Expected: `2 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Llm/LlmRequest.php app/Services/Llm/LlmResponse.php tests/Unit/Llm/LlmRequestTest.php
git commit -m "feat(llm): add LlmRequest and LlmResponse DTOs"
```

---

### Task C.3 — ProviderInterface + OpenAiProvider

**Files:**
- Create: `app/Services/Llm/Providers/ProviderInterface.php`
- Create: `app/Services/Llm/Providers/OpenAiProvider.php`
- Create: `tests/Unit/Llm/OpenAiProviderTest.php`
- Modify: `config/services.php` (only if `openai.*` keys are missing — they're already present)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Llm/OpenAiProviderTest.php`:

```php
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
```

- [ ] **Step 2: Run — expect fail**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=OpenAiProviderTest
```

- [ ] **Step 3: Create `ProviderInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;

interface ProviderInterface
{
    public function chat(LlmRequest $request): LlmResponse;

    public function name(): string;
}
```

- [ ] **Step 4: Create `OpenAiProvider.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Facades\Http;

final class OpenAiProvider implements ProviderInterface
{
    public function chat(LlmRequest $request): LlmResponse
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.openai.timeout', 45);

        $body = [
            'model' => $request->model,
            'messages' => $request->messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'store' => false,
        ];
        if (!empty($request->tools)) {
            $body['tools'] = $request->tools;
        }

        $start = microtime(true);
        $response = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/chat/completions", $body);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new \RuntimeException("OpenAI chat failed ({$response->status()}): " . $response->body());
        }

        $json = $response->json() ?? [];
        $choice = $json['choices'][0] ?? [];
        $usage = $json['usage'] ?? [];

        return new LlmResponse(
            content: (string) ($choice['message']['content'] ?? ''),
            role: (string) ($choice['message']['role'] ?? 'assistant'),
            provider: 'openai',
            model: (string) ($json['model'] ?? $request->model),
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
            latencyMs: $latencyMs,
            traceId: null,
            raw: $json,
        );
    }

    public function name(): string
    {
        return 'openai';
    }
}
```

- [ ] **Step 5: Run — expect green**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=OpenAiProviderTest
```

Expected: `2 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Llm/Providers/ProviderInterface.php app/Services/Llm/Providers/OpenAiProvider.php tests/Unit/Llm/OpenAiProviderTest.php
git commit -m "feat(llm): add OpenAiProvider with store=false enforcement"
```

---

### Task C.4 — TracerInterface + NullTracer

**Files:**
- Create: `app/Services/Llm/Tracing/TracerInterface.php`
- Create: `app/Services/Llm/Tracing/NullTracer.php`
- Create: `tests/Unit/Llm/NullTracerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Llm/NullTracerTest.php`:

```php
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
```

- [ ] **Step 2: Run — expect fail**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=NullTracerTest
```

- [ ] **Step 3: Create `TracerInterface.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm\Tracing;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;

interface TracerInterface
{
    public function startTrace(LlmRequest $request): string;

    public function recordResponse(string $traceId, LlmRequest $request, LlmResponse $response): void;

    public function recordError(string $traceId, LlmRequest $request, \Throwable $error): void;
}
```

- [ ] **Step 4: Create `NullTracer.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm\Tracing;

use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResponse;
use Illuminate\Support\Str;

final class NullTracer implements TracerInterface
{
    public function startTrace(LlmRequest $request): string
    {
        return (string) Str::uuid();
    }

    public function recordResponse(string $traceId, LlmRequest $request, LlmResponse $response): void
    {
        // intentionally empty
    }

    public function recordError(string $traceId, LlmRequest $request, \Throwable $error): void
    {
        // intentionally empty
    }
}
```

- [ ] **Step 5: Run — expect green**

- [ ] **Step 6: Commit**

```bash
git add app/Services/Llm/Tracing/TracerInterface.php app/Services/Llm/Tracing/NullTracer.php tests/Unit/Llm/NullTracerTest.php
git commit -m "feat(llm): add TracerInterface and NullTracer"
```

---

### Task C.5 — LangfuseTracer

**Files:**
- Create: `app/Services/Llm/Tracing/LangfuseTracer.php`
- Create: `tests/Unit/Llm/LangfuseTracerTest.php`
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add config block**

Append to `config/services.php` inside the returned array:

```php
    'langfuse' => [
        'public_key' => env('LANGFUSE_PUBLIC_KEY'),
        'secret_key' => env('LANGFUSE_SECRET_KEY'),
        'host' => env('LANGFUSE_HOST', 'https://cloud.langfuse.com'),
        'enabled' => env('LANGFUSE_ENABLED', false),
        'timeout' => (int) env('LANGFUSE_TIMEOUT_SECONDS', 5),
    ],
```

Append to `.env.example`:

```
# Langfuse (LLM observability)
LANGFUSE_ENABLED=false
LANGFUSE_PUBLIC_KEY=
LANGFUSE_SECRET_KEY=
LANGFUSE_HOST=https://cloud.langfuse.com
LANGFUSE_TIMEOUT_SECONDS=5
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Llm/LangfuseTracerTest.php`:

```php
<?php

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
```

- [ ] **Step 3: Run — expect fail**

- [ ] **Step 4: Implement `LangfuseTracer.php`**

```php
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
```

- [ ] **Step 5: Run — expect green**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=LangfuseTracerTest
```

Expected: `3 passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Llm/Tracing/LangfuseTracer.php tests/Unit/Llm/LangfuseTracerTest.php config/services.php .env.example
git commit -m "feat(llm): add LangfuseTracer with fire-and-forget ingestion"
```

---

### Task C.6 — LlmCall Eloquent model

**Files:**
- Create: `app/Models/LlmCall.php`
- Create: `tests/Unit/Models/LlmCallTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\LlmCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LlmCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_llm_call_persists_fields(): void
    {
        $call = LlmCall::create([
            'purpose' => 'generation',
            'provider' => 'openai',
            'model' => 'gpt-4o-2025-03-15',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'cost_usd_cents' => 3,
            'latency_ms' => 180,
            'trace_id' => 'trace_abc',
            'metadata' => ['temperature' => 0.3],
        ]);

        $fresh = LlmCall::findOrFail($call->id);
        $this->assertSame('openai', $fresh->provider);
        $this->assertSame(['temperature' => 0.3], $fresh->metadata);
        $this->assertSame('trace_abc', $fresh->trace_id);
    }
}
```

- [ ] **Step 2: Run — expect fail**

- [ ] **Step 3: Implement `LlmCall.php`**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmCall extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id', 'parent_llm_call_id',
        'purpose', 'provider', 'model',
        'prompt_tokens', 'completion_tokens',
        'cost_usd_cents', 'latency_ms',
        'trace_id', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Message::class, 'message_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_llm_call_id');
    }
}
```

- [ ] **Step 4: Run — expect green**

- [ ] **Step 5: Commit**

```bash
git add app/Models/LlmCall.php tests/Unit/Models/LlmCallTest.php
git commit -m "feat(llm): add LlmCall eloquent model"
```

---

### Task C.7 — LlmClient

**Files:**
- Create: `app/Services/Llm/LlmClient.php`
- Create: `config/llm.php`
- Create: `tests/Feature/Llm/LlmClientTest.php`

- [ ] **Step 1: Create `config/llm.php`**

```php
<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),
    'ledger_enabled' => env('LLM_LEDGER_ENABLED', true),
];
```

Append to `.env.example`:

```
# LlmClient abstraction
LLM_DEFAULT_PROVIDER=openai
LLM_LEDGER_ENABLED=true
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Llm/LlmClientTest.php`:

```php
<?php

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
        $this->assertArrayHasKey('error', $row->metadata);
    }
}
```

- [ ] **Step 3: Run — expect fail**

- [ ] **Step 4: Implement `LlmClient.php`**

```php
<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\LlmCall;
use App\Services\Llm\Providers\ProviderInterface;
use App\Services\Llm\Tracing\TracerInterface;

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
            $this->tracer->recordError($traceId, $request, $e);
            $this->writeLedgerError($request, $traceId, $e);
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
        if (!config('llm.ledger_enabled', true)) return;

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
        if (!config('llm.ledger_enabled', true)) return;

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
                'error' => $e->getMessage(),
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
            ],
        ]);
    }
}
```

- [ ] **Step 5: Bind in the container**

Create `app/Providers/LlmServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Llm\LlmClient;
use App\Services\Llm\Providers\OpenAiProvider;
use App\Services\Llm\Providers\ProviderInterface;
use App\Services\Llm\Tracing\LangfuseTracer;
use App\Services\Llm\Tracing\NullTracer;
use App\Services\Llm\Tracing\TracerInterface;
use Illuminate\Support\ServiceProvider;

final class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderInterface::class, function () {
            $provider = config('llm.default_provider', 'openai');
            return match ($provider) {
                'openai' => new OpenAiProvider(),
                default => throw new \RuntimeException("unknown LLM provider: {$provider}"),
            };
        });

        $this->app->bind(TracerInterface::class, function () {
            return config('services.langfuse.enabled')
                ? new LangfuseTracer()
                : new NullTracer();
        });

        $this->app->bind(LlmClient::class, function ($app) {
            return new LlmClient(
                $app->make(ProviderInterface::class),
                $app->make(TracerInterface::class),
            );
        });
    }
}
```

Register it in `bootstrap/providers.php` (add a line):

```php
App\Providers\LlmServiceProvider::class,
```

- [ ] **Step 6: Run — expect green**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=LlmClientTest
```

Expected: `2 passed`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Llm/LlmClient.php config/llm.php app/Providers/LlmServiceProvider.php bootstrap/providers.php tests/Feature/Llm/LlmClientTest.php .env.example
git commit -m "feat(llm): add LlmClient wiring provider, tracer, and llm_calls ledger"
```

---

### Task C.8 — Migrate ConversationController to LlmClient

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ConversationController.php` (line ~230–231)
- Modify: `app/Services/OpenAiService.php` (remove `chat()` method)

- [ ] **Step 1: Read the current call site**

Open `app/Http/Controllers/Api/V1/ConversationController.php` and find the block around line 230:

```php
$openai = app(OpenAiService::class);
$result = $openai->chat($messages, $agent->openai_model, $tools, (float) config('services.openai.temperature', 0.3));
```

Note: `$result` is used downstream as an associative array with keys `content`, `ai_provider`, `ai_model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `ai_latency_ms`. Grep for each usage to understand the contract before replacing.

- [ ] **Step 2: Replace the call**

```php
$client = app(\App\Services\Llm\LlmClient::class);
$response = $client->chat(new \App\Services\Llm\LlmRequest(
    messages: $messages,
    model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
    temperature: (float) config('services.openai.temperature', 0.3),
    maxTokens: (int) config('services.openai.max_output_tokens', 220),
    tools: $tools ?? [],
    purpose: 'generation',
    messageId: $message->id ?? null,
));

$result = [
    'content' => $response->content,
    'role' => $response->role,
    'ai_provider' => $response->provider,
    'ai_model' => $response->model,
    'prompt_tokens' => $response->promptTokens,
    'completion_tokens' => $response->completionTokens,
    'total_tokens' => $response->totalTokens,
    'ai_latency_ms' => $response->latencyMs,
    'trace_id' => $response->traceId,
];
```

(If `$message` isn't in scope at that line, pass `messageId: null` and wire the linkage later when the verification pipeline lands.)

Also add at the top of the file:

```php
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmRequest;
```

Remove the `use App\Services\OpenAiService;` line only if no other method in the controller uses it. Lines 154 and 169 use `OpenAiService` for STT/TTS — those stay.

- [ ] **Step 3: Remove `chat()` from `OpenAiService.php`**

Delete lines 21–58 (the entire `chat()` method and its docblock) from `app/Services/OpenAiService.php`. STT/TTS/files/vector-store stay.

- [ ] **Step 4: Run the hotel regression suite**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=HotelSpaRegressionTest
```

Expected: all assertions pass. If anything fails, revert Step 3 only and investigate — the controller code may reference `OpenAiService::chat` in a second place the grep missed.

- [ ] **Step 5: Run the full suite**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test
```

Expected: all green. Test count increases by 8–10 from earlier tasks.

- [ ] **Step 6: Manual SPA smoke**

Open `http://avatar.local/spa`, send a chat message to a hotel avatar, verify: response streams back, no 5xx in network tab, `storage/logs/laravel.log` clean.

Confirm a `llm_calls` row was written:

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan tinker --execute="echo DB::scalar('select count(*) from llm_calls');"
```

Expected: count ≥ 1.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/ConversationController.php app/Services/OpenAiService.php
git commit -m "refactor(chat): route hotel generation through LlmClient"
```

---

### Task C.9 — End-to-end Langfuse trace verification (manual)

**Files:** (none — verification only)

- [ ] **Step 1: Fill Langfuse credentials**

Edit local `.env` (NOT `.env.example`):

```
LANGFUSE_ENABLED=true
LANGFUSE_PUBLIC_KEY=pk_...
LANGFUSE_SECRET_KEY=sk_...
LANGFUSE_HOST=https://cloud.langfuse.com
```

Clear config cache:

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan config:clear
```

- [ ] **Step 2: Trigger one real chat**

Open `http://avatar.local/spa`, send one message. Wait ~5 seconds.

- [ ] **Step 3: Verify trace in Langfuse UI**

Log into `https://cloud.langfuse.com`, open the project, Traces tab. A `generation` trace should appear with:
- `model` matching the agent's `openai_model`
- `input` = the messages array
- `output` = the assistant response
- `usage` = prompt/completion/total tokens

- [ ] **Step 4: Verify the ledger row**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan tinker --execute="
\$r = DB::select('select provider, model, prompt_tokens, completion_tokens, trace_id from llm_calls order by id desc limit 1');
print_r(\$r);
"
```

Expected: one row with `trace_id` matching the UUID shown in Langfuse.

- [ ] **Step 5: Revert LANGFUSE_ENABLED=false in local `.env`**

Leave real credentials in place if you want them for dev work; set `LANGFUSE_ENABLED=false` again to avoid noisy dev traces.

(No commit — this is verification only.)

---

### Task C.10 — Sentry smoke test (manual)

**Files:** (none — verification only)

- [ ] **Step 1: Set a real DSN in local `.env`**

```
SENTRY_LARAVEL_DSN=https://....ingest.sentry.io/...
```

Clear config cache.

- [ ] **Step 2: Trigger an exception**

Temporarily add a route to `routes/web.php`:

```php
Route::get('/__sentry-boom', fn () => throw new \RuntimeException('sentry smoke'));
```

Hit `http://avatar.local/__sentry-boom`. Remove the route after verifying.

- [ ] **Step 3: Confirm event in Sentry**

Sentry → Issues. New issue titled "sentry smoke" from the Laravel environment. Scrubber should show no `message`/`prompt`/`response` fields in the request data.

- [ ] **Step 4: Revert DSN if desired**

Blank `SENTRY_LARAVEL_DSN` returns the SDK to no-op mode. Leave it enabled for dev if you want ongoing error capture.

(No commit — verification only.)

---

### Task C.11 — Update integration docs

**Files:**
- Modify: `docs/integrations/sentry.md`
- Modify: `docs/integrations/langfuse.md`
- Modify: `docs/integrations/openai.md`

- [ ] **Step 1: Flip `Status` to `live`**

In all three files, change `**Status:** planned` (or `live` if unchanged) to reflect reality:
- `sentry.md`: backend live, mobile planned.
- `langfuse.md`: live (via `LlmClient`).
- `openai.md`: already live — update the "Caller" column to point at `LlmClient::chat()` for `/v1/chat/completions`; keep STT/TTS/files rows pointing at `OpenAiService` (unchanged).

- [ ] **Step 2: Update the `Last verified` date**

Set to today (2026-04-20 or later).

- [ ] **Step 3: Append a change log entry**

Each file:

```markdown
- YYYY-MM-DD — wired via `LlmClient` / `LangfuseTracer` / `sentry-laravel` as part of the Phase 0 telemetry plan.
```

- [ ] **Step 4: Commit**

```bash
git add docs/integrations/sentry.md docs/integrations/langfuse.md docs/integrations/openai.md
git commit -m "docs(integrations): mark sentry/langfuse/openai live after telemetry wiring"
```

---

### Task C.12 — ADR

**Files:**
- Create: `docs/adr/YYYY-MM-DD-phase-0-telemetry-llm-client.md`

- [ ] **Step 1: Write the ADR**

```markdown
# ADR — Phase 0 Telemetry + LlmClient

**Date:** YYYY-MM-DD
**Status:** Accepted
**Authors:** platform

## Context

CLAUDE.md §Phase 0 names Sentry (backend + mobile) and Langfuse on one real
LLM call as exit criteria. It also requires a single LLM client abstraction
that unifies tracing, cost accounting, and ZDR enforcement. This ADR records
the Phase 0 decisions; multi-provider and verification-pipeline work is
Phase 1.

## Decisions

1. **Thin provider-agnostic client.** `LlmClient::chat(LlmRequest): LlmResponse`.
   `ProviderInterface` with only `chat()`. STT, TTS, file and vector-store
   calls stay in `OpenAiService` — they are not the observability gate.

2. **store=false unconditional on OpenAI.** Enforced in `OpenAiProvider`, not
   at the caller. Belt-and-braces with the dashboard-level opt-out documented
   in `docs/compliance/openai-zdr.md`. Formal ZDR remains pending; this rule
   does not change when ZDR lands.

3. **Langfuse via plain HTTP, no SDK.** The community `langfuse-php` package
   is sparsely maintained and the ingestion API (`POST /api/public/ingestion`
   with a `batch` of events) is small. A direct client keeps the dependency
   graph minimal and the tracing call-site under our control.

4. **Tracer is fire-and-forget.** Ingestion failures are swallowed with a log
   warning. Tracing never breaks the generation path (matches
   `docs/integrations/langfuse.md`).

5. **Ledger every call through LlmClient.** `llm_calls` row written on every
   invocation — success and failure. Error rows carry `metadata.error`. This
   is the cost/latency ground truth; Langfuse traces are the debugging lens.

6. **Sentry backend only in this phase.** `sentry/sentry-laravel` installed,
   DSN env-configured, before-send scrubber strips message/prompt/response
   keys from request data. Mobile Sentry deferred to the Expo skeleton plan
   because `mobile/` does not yet exist.

7. **Hotel chat migrates now, not later.** Phase 0 value is measured by the
   hotel regression staying green while going through the abstraction.
   Delaying the migration would leave two generation paths indefinitely.

## Consequences

- Adding Anthropic is a new `AnthropicProvider` + binding flip — no call-site
  changes, no tracer changes.
- Cost-aware orchestration (Phase 1) queries `llm_calls` for per-conversation
  totals without touching provider code.
- The verification pipeline (Phase 1) spawns child calls by passing
  `parentLlmCallId` into `LlmRequest`.
- Retry/backoff is still caller-side. Transient/rate-limit/content-policy
  classification is a Phase 1 addition to `OpenAiProvider`, mirrored on
  future providers.

## Alternatives considered

- **Streaming-first client.** Rejected — no streaming consumer today; SSE
  around a blocking call works for hotel. Streaming is a Phase 1+ addition
  when the wellness verification pipeline needs token-by-token output.
- **Use `openai-php/client` SDK inside LlmClient.** Rejected — three
  dependencies for three providers eventually; plain `Http::` keeps the
  abstraction uniform.
- **Wrap Langfuse via Sentry performance monitoring.** Rejected — Sentry is
  error-first; LLM observability warrants a purpose-built tool.

## Implementation notes

- `sentry:publish --dsn=...` writes `config/sentry.php` but also appends to
  `.env`. Revert `.env` changes; only `.env.example` should be committed.
- `Http::withBasicAuth($pub, $sec)` is the Langfuse auth pattern — the public
  key is the username, the secret is the password.
- `OpenAiService::chat()` deletion was only safe because a single grep
  confirmed one call site (`ConversationController` line ~231). Any new call
  that needs generation must use `LlmClient`.
```

Fill in today's date.

- [ ] **Step 2: Commit**

```bash
git add docs/adr/YYYY-MM-DD-phase-0-telemetry-llm-client.md
git commit -m "docs(adr): record Phase 0 telemetry + LlmClient decisions"
```

---

### Task C.13 — Merge checklist + final suite run

**Files:**
- Create: `docs/phases/phase-0-telemetry-llm-client-merge-checklist.md`

- [ ] **Step 1: Full automated suite**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test
```

Expected: all green. Test count ~ 53 (eval) + 10 (telemetry) = 63.

- [ ] **Step 2: Hotel regression explicit**

```bash
c:/wamp64/bin/php/php8.4.20/php.exe artisan test --filter=HotelSpaRegressionTest
```

- [ ] **Step 3: SPA smoke + trace verified**

Manual chat via `/spa`. Confirm:
- hotel avatar replies normally
- one new row in `llm_calls` with non-null `trace_id`, tokens, latency
- no entries in `storage/logs/laravel.log` at `error` level

- [ ] **Step 4: Write the merge checklist**

```markdown
# Phase 0 — Telemetry + LlmClient merge checklist

## Automated

- [ ] `php artisan test` — all green (adds LlmRequestTest, OpenAiProviderTest, NullTracerTest, LangfuseTracerTest, LlmCallTest, LlmClientTest).
- [ ] `php artisan test --filter=HotelSpaRegressionTest` — hotel unchanged.
- [ ] `php artisan test --filter=LlmClientTest` — ledger + error-ledger pass.
- [ ] `grep -r "OpenAiService::class" app` — only STT/TTS call sites remain; no `->chat(` via OpenAiService.

## Manual

- [ ] Hotel SPA chat round-trip succeeds against local `http://avatar.local/spa`.
- [ ] One real Langfuse trace visible in the Langfuse UI (Task C.9).
- [ ] One Sentry event visible in the Sentry UI from a thrown exception (Task C.10).
- [ ] `config/sentry.php` before-send scrubber strips `message`/`prompt`/`response` keys.
- [ ] `.env.example` lists SENTRY_LARAVEL_DSN, LANGFUSE_* variables, LLM_DEFAULT_PROVIDER, LLM_LEDGER_ENABLED.

## DB smoke-test

```sql
SELECT provider, model, prompt_tokens, completion_tokens, latency_ms, trace_id
FROM llm_calls ORDER BY id DESC LIMIT 5;
-- expect: rows with provider=openai, non-null tokens/latency/trace_id
```

## Not in this phase (verify no one added them)

- [ ] No AnthropicProvider or GoogleProvider class.
- [ ] No streaming support in LlmClient.
- [ ] No retry/backoff in OpenAiProvider.
- [ ] No mobile Sentry code (waits for Expo skeleton plan).
- [ ] No PostHog wiring (waits for Phase 3).
```

- [ ] **Step 5: Commit**

```bash
git add docs/phases/phase-0-telemetry-llm-client-merge-checklist.md
git commit -m "docs(phases): phase-0 telemetry + llm-client merge checklist"
```

---

## Part C — Exit criteria

Phase 0 telemetry + LlmClient is done when:

- [ ] All 14 tasks above (C.0–C.13) are committed.
- [ ] `php artisan test` passes in full.
- [ ] Hotel SPA chat works end-to-end against local and writes an `llm_calls` row per reply.
- [ ] One real Langfuse trace observed in the Langfuse UI.
- [ ] One real Sentry event observed in the Sentry UI.
- [ ] `OpenAiService::chat()` no longer exists; `ConversationController` uses `LlmClient`.
- [ ] Merge checklist all checked.
- [ ] ADR recorded.

**Follow-up plans after this lands:**
- Mobile shell (Expo + Sanctum auth + `/up` round-trip + mobile Sentry).
- ml-service scaffold (FastAPI `/healthz` only).
- OpenAI ZDR formal confirmation (non-code; update `docs/compliance/openai-zdr.md` when OpenAI responds).
