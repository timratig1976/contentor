<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Persona extends Model
{
    protected $fillable = [
        'unit_id', 'name', 'role', 'voice', 'topics', 'cadence', 'active',
    ];

    protected $casts = [
        'topics' => 'array',
        'active' => 'boolean',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
