<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\LlmCall;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Models\RedFlagEvent;
use App\Models\VerificationEvent;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagePipelineTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_pipeline_tables_exist_and_link(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $conv = Conversation::first();
        $agent = Agent::find($conv->agent_id);

        $msg = Message::create([
            'conversation_id' => $conv->id,
            'agent_id' => $agent->id,
            'role' => 'agent',
            'content' => 'grounded reply',
            'verification_status' => 'passed',
        ]);

        MessageCitation::create([
            'message_id' => $msg->id,
            'chunk_id' => null,
            'external_source_id' => null,
            'label' => 'Source A',
            'span_start' => 0,
            'span_end' => 14,
        ]);

        VerificationEvent::create([
            'message_id' => $msg->id,
            'stage' => 'grounding',
            'passed' => true,
            'notes' => ['grounded_claims' => 3, 'total_claims' => 3],
        ]);

        LlmCall::create([
            'message_id' => $msg->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'cost_usd_cents' => 3,
            'latency_ms' => 840,
            'trace_id' => 'test-trace',
        ]);

        RedFlagEvent::create([
            'conversation_id' => $conv->id,
            'message_id' => $msg->id,
            'rule_slug' => 'self-harm',
            'severity' => 'critical',
            'payload' => [],
        ]);

        $this->assertSame(1, MessageCitation::where('message_id', $msg->id)->count());
        $this->assertSame(1, VerificationEvent::where('message_id', $msg->id)->count());
        $this->assertSame(1, LlmCall::where('message_id', $msg->id)->count());
        $this->assertSame(1, RedFlagEvent::where('message_id', $msg->id)->count());
        $this->assertSame('passed', $msg->fresh()->verification_status);
    }

    public function test_existing_agent_messages_have_agent_id_backfilled(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Message::where('role', 'agent')->get()->each(
            fn (Message $m) => $this->assertNotNull($m->agent_id, "Agent message {$m->id} missing agent_id after backfill")
        );
    }
}
