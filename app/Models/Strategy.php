<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Strategy extends Model
{
    use HasFactory;

    protected $table = 'strategies';

    protected $fillable = ['key', 'name', 'config'];

    protected $casts = [
        'config' => 'array',
    ];

    /**
     * Löscht die Strategie KASKADENHAFT zusammen mit ALLEN zugehörigen Daten.
     *
     * Fail-safe:
     *  - Läuft atomar in einer Transaktion (alles oder nichts).
     *  - Löscht die Kinder explizit in topologischer Reihenfolge, unabhängig
     *    davon, ob der Dialekt (z. B. SQLite) ON DELETE CASCADE unterstützt.
     *  - Wird bei einem Fehler zurückgerollt -> kein Teilzustand.
     *
     * @return array<string,int> Anzahl gelöschter Zeilen pro Tabelle
     */
    public function deleteCascade(): array
    {
        return DB::transaction(function () {
            $sid = $this->id;
            $counts = [];

            // 1) Content-Medien (hängt von content_items ab)
            $counts['content_media'] = ContentMedia::whereIn(
                'content_item_id',
                ContentItem::where('strategy_id', $sid)->select('id')
            )->delete();

            // 2) Redaktionsplan (hängt von content_items ab)
            $counts['redaktionsplan_entries'] = RedaktionsplanEntry::whereIn(
                'content_item_id',
                ContentItem::where('strategy_id', $sid)->select('id')
            )->delete();

            // 3) Content-Items
            $counts['content_items'] = ContentItem::where('strategy_id', $sid)->delete();

            // 4) Angles
            $counts['angles'] = Angle::where('strategy_id', $sid)->delete();

            // 5) Quellen
            $counts['sources'] = Source::where('strategy_id', $sid)->delete();

            // 6) Persona-Mappings (Personas sind global → nur Pivot-Rows löschen)
            $counts['personas'] = DB::table('persona_strategy_map')
                ->where('strategy_id', $sid)
                ->delete();

            // 7) Content-Strategie-Blöcke (Brand Voice, Kanal-Regeln, ...)
            $counts['content_strategies'] = ContentStrategy::where('strategy_id', $sid)->delete();

            // 8) Die Strategie selbst
            $this->delete();

            return $counts;
        });
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class, 'strategy_id');
    }

    public function angles(): HasMany
    {
        return $this->hasMany(Angle::class, 'strategy_id');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'strategy_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContentMedia::class, 'strategy_id');
    }

    /**
     * Globale Personas, die dieser Strategie zugeordnet sind (via pivot).
     * Pivot-Felder: angles, topic_clusters, is_default (pro Strategie).
     */
    public function personas(): BelongsToMany
    {
        return $this->belongsToMany(Persona::class, 'persona_strategy_map', 'strategy_id', 'persona_id')
            ->using(PersonaStrategyMap::class)
            ->withPivot(['mapped_angles', 'mapped_topics', 'is_default'])
            ->withTimestamps();
    }

    public function redaktionsplanEntries(): HasMany
    {
        return $this->hasMany(RedaktionsplanEntry::class, 'strategy_id');
    }

    public function contentStrategies(): HasMany
    {
        return $this->hasMany(ContentStrategy::class, 'strategy_id');
    }

    // Accessors
    public function getRulesAttribute(): array
    {
        return $this->config['rules'] ?? [];
    }

    public function getClustersAttribute(): array
    {
        return $this->rules['clusters'] ?? [];
    }

    public function getIcpGuesserAttribute(): array
    {
        return $this->rules['icpGuesser'] ?? [];
    }

    public function getDefaultIcpAttribute(): string
    {
        return $this->rules['defaultIcp'] ?? 'B2B-1';
    }

    public function getDefaultClusterKeyAttribute(): string
    {
        return $this->rules['defaultClusterKey'] ?? 'cluster_1';
    }

    public function getHashtagsAttribute(): array
    {
        return $this->rules['hashtags'] ?? [];
    }

    public function getForbiddenPatternsAttribute(): array
    {
        return $this->rules['forbiddenPatterns'] ?? [];
    }
}