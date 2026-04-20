<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalCase extends Model
{
    protected $table = 'eval_cases';

    protected $fillable = [
        'dataset_id', 'slug', 'prompt',
        'context_json', 'stub_response', 'assertions_json',
    ];

    protected $casts = [
        'context_json' => 'array',
        'assertions_json' => 'array',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvalDataset::class, 'dataset_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class, 'case_id');
    }
}
