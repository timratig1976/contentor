<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'strategy_id', 'title', 'type', 'visibility',
        'file_ref', 'batch_key', 'url', 'monitor', 'frequency',
        'last_checked_at', 'content_hash', 'last_content_preview',
        'raw_content',
    ];

    protected $casts = [
        'monitor' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    public function angles(): HasMany
    {
        return $this->hasMany(Angle::class, 'source_id');
    }

    /**
     * URL-Accessor: fällt auf `file_ref` zurück, falls die dedizierte
     * `url`-Spalte leer ist (historische Daten pre-Monitoring).
     */
    public function getUrlAttribute(?string $value): ?string
    {
        if (! empty($value)) {
            return $value;
        }

        if ($this->type === 'url' && $this->file_ref && str_starts_with($this->file_ref, 'http')) {
            return $this->file_ref;
        }

        return $value;
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Source $model) {
            if (empty($model->id)) {
                $model->id = 'SRC-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }
}
