<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvalResult extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'case_id', 'assertion_index',
        'assertion_type', 'passed', 'actual_response', 'reason',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'run_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(EvalCase::class, 'case_id');
    }
}
