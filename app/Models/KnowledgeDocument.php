<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    protected $fillable = ['agent_id', 'title', 'source_url', 'evidence_grade', 'licence', 'locale', 'checksum', 'metadata', 'ingested_at', 'retired_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'ingested_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }
}
