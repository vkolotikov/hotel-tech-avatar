<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageCitation extends Model
{
    protected $fillable = ['message_id', 'chunk_id', 'external_source_id', 'label', 'span_start', 'span_end'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'chunk_id');
    }
}
