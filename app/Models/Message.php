<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'role', 'content',
        'ai_provider', 'ai_model',
        'prompt_tokens', 'completion_tokens', 'total_tokens',
        'ai_latency_ms', 'ui_json',
        'retrieval_used', 'retrieval_source_count',
    ];

    protected function casts(): array
    {
        return [
            'ui_json'                => 'array',
            'retrieval_used'         => 'boolean',
            'prompt_tokens'          => 'integer',
            'completion_tokens'      => 'integer',
            'total_tokens'           => 'integer',
            'ai_latency_ms'         => 'integer',
            'retrieval_source_count' => 'integer',
            'created_at'             => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
