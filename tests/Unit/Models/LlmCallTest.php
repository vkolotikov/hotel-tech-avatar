<?php

declare(strict_types=1);

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
