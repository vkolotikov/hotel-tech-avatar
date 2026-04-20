<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEntitlement extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'status', 'trial_ends_at', 'renews_at', 'billing_provider', 'billing_customer_id', 'billing_metadata'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'renews_at' => 'datetime', 'billing_metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
