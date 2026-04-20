<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalDataset extends Model
{
    protected $fillable = [
        'slug', 'name', 'vertical_slug', 'avatar_slug',
        'description', 'source_path', 'source_hash',
    ];

    public function cases(): HasMany
    {
        return $this->hasMany(EvalCase::class, 'dataset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvalRun::class, 'dataset_id');
    }
}
