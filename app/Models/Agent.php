<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'vertical_id',
        'slug', 'name', 'role', 'domain', 'description',
        'avatar_image_url', 'chat_background_url', 'intro_video_url',
        'system_instructions', 'knowledge_text', 'knowledge_files_json', 'knowledge_sources_json',
        'openai_model', 'openai_voice',
        'liveavatar_avatar_id', 'liveavatar_voice_id',
        'use_advanced_ai', 'openai_vector_store_id',
        'knowledge_sync_status', 'knowledge_synced_at', 'knowledge_last_error',
        'is_published',
        'persona_json', 'scope_json', 'red_flag_rules_json', 'handoff_rules_json',
        'prompt_suggestions_json',
        'active_prompt_version_id',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_files_json'     => 'array',
            'knowledge_sources_json'   => 'json',
            'use_advanced_ai'          => 'boolean',
            'is_published'             => 'boolean',
            'knowledge_synced_at'      => 'datetime',
            'persona_json'             => 'array',
            'scope_json'               => 'array',
            'red_flag_rules_json'      => 'array',
            'handoff_rules_json'       => 'array',
            'prompt_suggestions_json'  => 'array',
        ];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function knowledgeFiles(): HasMany
    {
        return $this->hasMany(AgentKnowledgeFile::class);
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(AgentPromptVersion::class);
    }

    public function activePromptVersion(): BelongsTo
    {
        return $this->belongsTo(AgentPromptVersion::class, 'active_prompt_version_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForVertical($query, string $slug)
    {
        return $query->whereHas('vertical', fn ($q) => $q->where('slug', $slug));
    }
}
