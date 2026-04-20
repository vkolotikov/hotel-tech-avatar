<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsageDaily extends Model
{
    protected $table = 'token_usage_daily';

    protected $fillable = ['user_id', 'usage_date', 'messages_count', 'tokens_in', 'tokens_out', 'cost_usd_cents'];

    protected function casts(): array
    {
        return ['usage_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
