<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory;
    protected $fillable = ['key', 'name', 'config'];

    protected $casts = [
        'config' => 'array',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function angles(): HasMany
    {
        return $this->hasMany(Angle::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContentMedia::class);
    }

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }

    public function redaktionsplanEntries(): HasMany
    {
        return $this->hasMany(RedaktionsplanEntry::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(ContentStrategy::class);
    }

    public function getRulesAttribute(): array
    {
        return $this->config['rules'] ?? [];
    }

    public function getClustersAttribute(): array
    {
        return $this->config['rules']['clusters'] ?? [];
    }

    public function getIcpGuesserAttribute(): array
    {
        return $this->config['rules']['icpGuesser'] ?? [];
    }

    public function getDefaultIcpAttribute(): string
    {
        return $this->config['rules']['defaultIcp'] ?? 'B2B-1';
    }

    public function getDefaultClusterKeyAttribute(): string
    {
        return $this->config['rules']['defaultClusterKey'] ?? 'cluster_1';
    }

    public function getHashtagsAttribute(): array
    {
        return $this->config['rules']['hashtags'] ?? [];
    }

    public function getForbiddenPatternsAttribute(): array
    {
        return $this->config['rules']['forbiddenPatterns'] ?? [];
    }
}
