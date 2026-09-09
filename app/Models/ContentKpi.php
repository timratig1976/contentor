<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Performance-Daten eines veröffentlichten Content-Items.
 *
 * Ein Item kann mehrere Messungen haben (z. B. 7 Tage, 30 Tage nach
 * Veröffentlichung) — eindeutig pro (content_item_id, measured_at).
 */
class ContentKpi extends Model
{
    protected $table = 'content_kpis';

    protected $fillable = [
        'strategy_id', 'content_item_id', 'measured_at',
        'impressions', 'reach', 'clicks', 'likes', 'comments', 'shares',
        'leads', 'conversions', 'revenue_eur',
        'open_rate', 'click_rate',
        'primary_metric', 'primary_value', 'notes',
    ];

    protected $casts = [
        'measured_at' => 'date',
        'impressions' => 'integer',
        'reach' => 'integer',
        'clicks' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'leads' => 'integer',
        'conversions' => 'integer',
        'revenue_eur' => 'decimal:2',
        'open_rate' => 'float',
        'click_rate' => 'float',
        'primary_value' => 'float',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'content_item_id');
    }

    /**
     * Engagement-Rate in % bezogen auf Impressions.
     */
    public function engagementRate(): float
    {
        $base = $this->impressions ?: $this->reach;
        if (! $base) {
            return 0.0;
        }

        return round((($this->likes + $this->comments + $this->shares) / $base) * 100, 2);
    }

    /**
     * Click-Through-Rate in % bezogen auf Impressions.
     */
    public function ctr(): float
    {
        $base = $this->impressions ?: $this->reach;
        if (! $base) {
            return 0.0;
        }

        return round(($this->clicks / $base) * 100, 2);
    }
}
