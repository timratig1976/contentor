<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentMedia extends Model
{
    protected $table = 'content_media';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'content_item_id', 'strategy_id', 'type', 'url',
        'drive_file_id', 'format', 'status', 'briefing', 'position',
    ];

    protected $casts = [
        'briefing' => 'array',
        'position' => 'integer',
    ];

    public const STATUSES = ['briefing', 'generiert', 'in_drive', 'live', 'verworfen'];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (ContentMedia $model) {
            if (empty($model->id)) {
                $model->id = 'MED-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }
}
