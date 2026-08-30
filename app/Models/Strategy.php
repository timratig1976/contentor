<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Strategy extends Model
{
    use HasFactory;

    protected $table = 'strategies';

    protected $fillable = ['key', 'name', 'config'];

    protected $casts = [
        'config' => 'array',
    ];

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

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class, 'strategy_id');
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