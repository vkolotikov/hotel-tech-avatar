<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vertical extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'is_active', 'launched_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'launched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
