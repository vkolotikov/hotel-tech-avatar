<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'agent_id', 'role', 'content',
        'ai_provider', 'ai_model',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'ai_latency_ms', 'ui_json',
        'retrieval_used', 'retrieval_source_count',
        'verification_status', 'handoff_from_agent_id',
        'claim_count', 'grounded_claim_count', 'red_flag_triggered',
    ];

    protected function casts(): array
    {
        return [
            'ui_json'                => 'array',
            'retrieval_used'         => 'boolean',
            'prompt_tokens'          => 'integer',
            'completion_tokens'      => 'integer',
            'total_tokens'           => 'integer',
            'ai_latency_ms'          => 'integer',
            'retrieval_source_count' => 'integer',
            'claim_count'            => 'integer',
            'grounded_claim_count'   => 'integer',
            'red_flag_triggered'     => 'boolean',
            'created_at'             => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function handoffFromAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'handoff_from_agent_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(MessageCitation::class);
    }

    public function verificationEvents(): HasMany
    {
        return $this->hasMany(VerificationEvent::class);
    }

    public function llmCalls(): HasMany
    {
        return $this->hasMany(LlmCall::class);
    }
}
