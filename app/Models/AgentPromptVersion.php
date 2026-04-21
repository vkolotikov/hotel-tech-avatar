<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPromptVersion extends Model
{
    protected $fillable = [
        'agent_id', 'version_number', 'system_instructions', 'system_prompt',
        'persona_json', 'scope_json', 'red_flag_rules_json', 'handoff_rules_json',
        'canned_responses_json',
        'is_active', 'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'persona_json' => 'array',
            'scope_json' => 'array',
            'red_flag_rules_json' => 'array',
            'handoff_rules_json' => 'array',
            'canned_responses_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Alias for system_instructions to support both naming conventions.
     */
    public function getSystemPromptAttribute(): ?string
    {
        return $this->system_instructions;
    }

    public function setSystemPromptAttribute(string $value): void
    {
        $this->system_instructions = $value;
    }
}
