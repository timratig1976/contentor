<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RedaktionsplanEntry extends Model
{
    protected $fillable = [
        'unit_id', 'content_item_id', 'planned_date',
        'channel', 'status', 'notes',
    ];

    protected $casts = [
        'planned_date' => 'date',
    ];

    public const STATUSES = ['geplant', 'in_arbeit', 'fertig', 'live'];
    public const KANBAN_COLUMNS = ['idee', 'angle', 'in_produktion', 'review', 'geplant', 'live'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }
}
