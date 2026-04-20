<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dataset_id', 'started_at', 'finished_at',
        'cases_total', 'cases_passed', 'cases_failed',
        'score_pct', 'trigger', 'trace_id', 'metadata_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata_json' => 'array',
        'score_pct' => 'decimal:2',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvalDataset::class, 'dataset_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class, 'run_id');
    }
}
