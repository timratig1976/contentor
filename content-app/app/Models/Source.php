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
        'id', 'unit_id', 'title', 'type', 'visibility',
        'file_ref', 'batch_key',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function angles(): HasMany
    {
        return $this->hasMany(Angle::class, 'source_id');
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
