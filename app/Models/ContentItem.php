<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentItem extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'strategy_id', 'angle_id', 'type', 'format', 'title',
        'content', 'status', 'owner', 'live_date', 'persona_id',
        'icp', 'pain_cluster', 'statement_type',
        'variant_group_id', 'variant_pattern',
    ];

    protected $casts = [
        'live_date' => 'date',
    ];

    public const STATUSES = ['idee', 'angle', 'in_produktion', 'review', 'geplant', 'live', 'verworfen'];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    public function angle(): BelongsTo
    {
        return $this->belongsTo(Angle::class, 'angle_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContentMedia::class, 'content_item_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function redaktionsplanEntry(): HasOne
    {
        return $this->hasOne(RedaktionsplanEntry::class, 'content_item_id');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (ContentItem $model) {
            if (empty($model->id)) {
                $model->id = 'CNT-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }
}
