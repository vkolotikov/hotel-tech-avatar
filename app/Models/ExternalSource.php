<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalSource extends Model
{
    protected $table = 'external_source_cache';

    protected $fillable = ['provider', 'external_id', 'title', 'url', 'payload', 'fetched_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'fetched_at' => 'datetime'];
    }
}
