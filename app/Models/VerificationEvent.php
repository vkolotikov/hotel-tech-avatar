<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'stage', 'passed', 'notes'];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'notes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
