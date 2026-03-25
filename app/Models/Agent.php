<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'slug', 'name', 'role', 'description',
        'avatar_image_url', 'chat_background_url',
        'system_instructions', 'knowledge_text', 'knowledge_files_json',
        'openai_model', 'openai_voice',
        'use_advanced_ai', 'openai_vector_store_id',
        'knowledge_sync_status', 'knowledge_synced_at', 'knowledge_last_error',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_files_json' => 'array',
            'use_advanced_ai'      => 'boolean',
            'is_published'         => 'boolean',
            'knowledge_synced_at'  => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function knowledgeFiles(): HasMany
    {
        return $this->hasMany(AgentKnowledgeFile::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
