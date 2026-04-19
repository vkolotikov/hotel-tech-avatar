<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'agent_id',
        'vertical_id',
        'user_id',
        'title',
        'summary_json',
        'last_activity_at',
        'session_cost_usd_cents',
    ];

    protected function casts(): array
    {
        return [
            'summary_json' => 'array',
            'last_activity_at' => 'datetime',
            'session_cost_usd_cents' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ConversationAttachment::class);
    }
}
