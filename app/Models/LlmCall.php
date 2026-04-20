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
