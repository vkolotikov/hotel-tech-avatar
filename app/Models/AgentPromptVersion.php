<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPromptVersion extends Model
{
    protected $fillable = [
        'agent_id', 'version_number', 'system_instructions',
        'persona_json', 'scope_json', 'red_flag_rules_json', 'handoff_rules_json',
        'is_active', 'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'persona_json' => 'array',
            'scope_json' => 'array',
            'red_flag_rules_json' => 'array',
            'handoff_rules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
