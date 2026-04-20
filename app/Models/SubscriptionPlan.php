<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = ['slug', 'name', 'price_usd_cents_monthly', 'price_usd_cents_annual', 'daily_message_limit', 'memory_days', 'features', 'is_active'];

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class, 'plan_id');
    }
}
