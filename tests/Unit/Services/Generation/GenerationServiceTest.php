<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Generation;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Vertical;
use App\Services\Generation\GenerationService;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LlmClient|MockInterface $llmClient;
    private VerificationServiceInterface|MockInterface $verificationService;
    private GenerationService $generationService;
    private Agent $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $vertical = Vertical::firstOrCreate(['slug' => 'hotel']);
        $this->agent = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'concierge']);

        $this->conversation = $this->agent->conversations()->create(['title' => 'Test']);

        $this->llmClient = $this->mock(LlmClient::class);
        $this->verificationService = $this->mock(VerificationServiceInterface::class);

        $this->generationService = new GenerationService(
            $this->llmClient,
            $this->verificationService,
        );
    }
}
