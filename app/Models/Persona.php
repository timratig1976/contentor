<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    use HasFactory;
    // Persona = Identität nur. Themen-Cluster + Angles leben im Strategie-Mapping.
    protected $fillable = [
        'strategy_id', 'name', 'role', 'voice',
        'core_statements', 'tonality', 'positioning',
        'content_attributes',
        'cadence', 'channel_strategies', 'active',
        'forbidden_words', 'max_sentence_length', 'emoji_usage', 'perspective',
    ];

    protected $casts = [
        'core_statements' => 'array',
        'tonality' => 'array',
        'channel_strategies' => 'array',
        'content_attributes' => 'array',
        'forbidden_words' => 'array',
        'max_sentence_length' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * Legacy: ursprüngliche Strategie (wird beim Mapping über pivot ersetzt).
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    /**
     * Globale Persona: kann mehreren Strategien zugeordnet sein.
     * Pivot-Felder: angles, topic_clusters, is_default (pro Strategie).
     */
    public function strategies(): BelongsToMany
    {
        return $this->belongsToMany(Strategy::class, 'persona_strategy_map', 'persona_id', 'strategy_id')
            ->using(PersonaStrategyMap::class)
            ->withPivot(['mapped_angles', 'mapped_topics', 'is_default'])
            ->withTimestamps();
    }

    /**
     * Kuratierte Few-Shot-Referenz-Beispiele (pro Format + Strategie).
     */
    public function examples(): HasMany
    {
        return $this->hasMany(PersonaExample::class);
    }

    /**
     * Liest die pro-Strategie-Mapping-Daten (angles, topics) für eine Persona.
     * Themen + Angles sind ausschließlich pro Strategie definiert.
     */
    public function strategyMapping(?int $strategyId): ?array
    {
        if (!$strategyId) {
            return null;
        }
        $strategy = $this->strategies->firstWhere('id', $strategyId);
        if (!$strategy) {
            return null;
        }
        $pivot = $strategy->pivot;
        return [
            'angles'         => $pivot->mapped_angles ?? [],
            'topic_clusters' => $pivot->mapped_topics ?? [],
            'is_default'     => (bool) ($pivot->is_default ?? false),
        ];
    }

    /**
     * Legacy-Kompatibilität: Wer ein strategy_id setzt (z. B. per Factory/Seeder),
     * bekommt automatisch ein Default-Mapping-Pivot.
     */
    protected static function booted(): void
    {
        static::created(function (Persona $persona) {
            if ($persona->strategy_id) {
                $persona->strategies()->syncWithoutDetaching([$persona->strategy_id => [
                    'is_default' => true,
                ]]);
            }
        });
    }
}
