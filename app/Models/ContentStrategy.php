<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentStrategy extends Model
{
    protected $fillable = [
        'strategy_id', 'key', 'content', 'version',
    ];

    protected $casts = [
        'content' => 'array',
        'version' => 'integer',
    ];

    public const KEYS = [
        'brand_voice',
        'channel_rules',
        'icp_definitions',
        'media_logic',
        'editorial_rhythm',
        'content_strategy',
        'post_templates',
        'content_personas',
    ];

    public const LABELS = [
        'brand_voice' => 'Brand Voice',
        'channel_rules' => 'Kanal-Regelwerk',
        'icp_definitions' => 'ICP-Definitionen',
        'media_logic' => 'Medien-Logik',
        'editorial_rhythm' => 'Redaktions-Rhythmus',
        'content_strategy' => 'Content-Strategie',
        'post_templates' => 'Post-Patterns',
        'content_personas' => 'Personen-Posts & Themen',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }
}
