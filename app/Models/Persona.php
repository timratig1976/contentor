<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Persona extends Model
{
    use HasFactory;
    protected $fillable = [
        'strategy_id', 'name', 'role', 'voice',
        'core_statements', 'tonality', 'positioning',
        'topics', 'angles', 'content_attributes',
        'cadence', 'channel_strategies', 'active',
    ];

    protected $casts = [
        'topics' => 'array',
        'core_statements' => 'array',
        'angles' => 'array',
        'tonality' => 'array',
        'channel_strategies' => 'array',
        'content_attributes' => 'array',
        'active' => 'boolean',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }
}
