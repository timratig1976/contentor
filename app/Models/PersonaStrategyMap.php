<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot: globale Persona ↔ Strategie.
 * Speichert pro Strategie die relevanten Angles + Themen-Cluster.
 */
class PersonaStrategyMap extends Pivot
{
    protected $table = 'persona_strategy_map';

    protected $casts = [
        'mapped_angles' => 'array',
        'mapped_topics' => 'array',
        'is_default' => 'boolean',
    ];
}
