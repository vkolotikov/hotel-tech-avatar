<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmCall extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id', 'parent_llm_call_id', 'purpose', 'provider', 'model',
        'prompt_tokens', 'completion_tokens', 'cost_usd_cents', 'latency_ms',
        'trace_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_usd_cents' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(LlmCall::class, 'parent_llm_call_id');
    }
}
