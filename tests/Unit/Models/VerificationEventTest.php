<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\VerificationEvent;
use Database\Factories\ConversationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_event_can_be_created_with_required_fields(): void
    {
        $conversation = Conversation::factory()->create();
        $message = Message::factory()->create(['conversation_id' => $conversation->id]);
        $avatar = Agent::factory()->create();

        $event = VerificationEvent::create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'avatar_id' => $avatar->id,
            'vertical_slug' => 'wellness',
            'response_text' => 'This is a test response.',
            'is_verified' => true,
            'revision_count' => 1,
        ]);

        $this->assertNotNull($event->id);
        $this->assertSame($conversation->id, $event->conversation_id);
        $this->assertSame($message->id, $event->message_id);
        $this->assertSame($avatar->id, $event->avatar_id);
        $this->assertSame('wellness', $event->vertical_slug);
        $this->assertSame('This is a test response.', $event->response_text);
        $this->assertTrue($event->is_verified);
        $this->assertSame(1, $event->revision_count);
    }

    public function test_json_arrays_are_cast_properly(): void
    {
        $conversation = Conversation::factory()->create();

        $event = VerificationEvent::create([
            'conversation_id' => $conversation->id,
            'response_text' => 'Test response',
            'failures_json' => ['error' => 'test error', 'code' => 'E001'],
            'safety_flags_json' => ['flag1' => true, 'flag2' => false],
        ]);

        $fresh = VerificationEvent::findOrFail($event->id);
        $this->assertIsArray($fresh->failures_json);
        $this->assertEqualsCanonicalizing(['error' => 'test error', 'code' => 'E001'], $fresh->failures_json);
        $this->assertIsArray($fresh->safety_flags_json);
        $this->assertEqualsCanonicalizing(['flag1' => true, 'flag2' => false], $fresh->safety_flags_json);
    }

    public function test_belongs_to_conversation_relationship_works(): void
    {
        $conversation = Conversation::factory()->create();
        $event = VerificationEvent::create([
            'conversation_id' => $conversation->id,
            'response_text' => 'Test response',
        ]);

        $this->assertInstanceOf(Conversation::class, $event->conversation);
        $this->assertSame($conversation->id, $event->conversation->id);
    }
}
