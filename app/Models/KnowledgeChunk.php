<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = ['document_id', 'agent_id', 'chunk_index', 'content', 'metadata', 'embedding'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * Mutator: Convert embedding array to pgvector string format for storage.
     * pgvector expects format: "[1.0, 2.0, 3.0, ...]"
     */
    public function setEmbeddingAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['embedding'] = '[' . implode(',', $value) . ']';
        } else {
            $this->attributes['embedding'] = $value;
        }
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
