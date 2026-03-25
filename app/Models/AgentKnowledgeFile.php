<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentKnowledgeFile extends Model
{
    protected $fillable = [
        'agent_id', 'local_path', 'file_hash', 'mime_type', 'size_bytes',
        'openai_file_id', 'vector_store_id', 'sync_status', 'last_error',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
