<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationEvent extends Model
{
    protected $fillable = [
        'conversation_id',
        'message_id',
        'avatar_id',
        'vertical_slug',
        'response_text',
        'is_verified',
        'revision_count',
        'failures_json',
        'safety_flags_json',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'failures_json' => 'array',
            'safety_flags_json' => 'array',
            'is_verified' => 'boolean',
            'revision_count' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'avatar_id');
    }
}
