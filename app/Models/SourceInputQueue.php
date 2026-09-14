<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Item aus einer automatisierten Quelle (RSS-Artikel, Video-Chunk,
 * Community-Post …), das auf Angle-Extraktion und anschließende
 * Nutzer-Freigabe wartet.
 */
class SourceInputQueue extends Model
{
    protected $table = 'source_input_queue';

    public const STATUSES = ['pending', 'processing', 'done', 'rejected'];

    protected $fillable = [
        'source_id', 'strategy_id', 'raw_content', 'item_title', 'item_url',
        'item_guid', 'status', 'extracted_angles', 'batch_key',
    ];
    // raw_content darf nachträglich angereichert werden (Volltext-Scrape)

    protected $casts = [
        'extracted_angles' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }
}
