<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Angle extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'source_id', 'strategy_id', 'batch_key', 'angle', 'icp',
        'pain_cluster', 'statement_type', 'funnel', 'viscale_phase',
        'r_zielgruppe', 'r_viscale_fit', 'r_schaerfe', 'r_timing',
        'ranking_score', 'ranking_rang', 'status',
        'embedding', 'score_reasoning', 'duplicate_of_id', 'similarity_score',
    ];

    protected $casts = [
        'r_zielgruppe' => 'integer',
        'r_viscale_fit' => 'integer',
        'r_schaerfe' => 'integer',
        'r_timing' => 'integer',
        'ranking_score' => 'integer',
        'ranking_rang' => 'integer',
        'similarity_score' => 'float',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'angle_id');
    }

    public function calculateScore(): ?int
    {
        $scores = [$this->r_zielgruppe, $this->r_viscale_fit, $this->r_schaerfe, $this->r_timing];
        if (in_array(null, $scores, true)) {
            return null;
        }
        return array_sum($scores);
    }

    public function updateRanking(): void
    {
        $this->ranking_score = $this->calculateScore();

        // Auto-Approve: Score-Threshold aus Strategie-Config.
        // null = frisch per create() erzeugt (DB-Default 'neu' noch nicht geladen).
        if ($this->ranking_score !== null && in_array($this->status, [null, 'neu'], true)) {
            $threshold = $this->autoApproveThreshold();
            if ($threshold !== null && $this->ranking_score >= $threshold) {
                $this->status = 'approved';
            } else {
                $this->status = 'bewertet';
            }
        }

        $this->save();
        static::recalculateRanks($this->strategy_id, $this->batch_key);
    }

    /**
     * Liest den Auto-Approve-Threshold aus der Strategie-Config.
     * Rückgabe null = immer manuelle Review.
     */
    public function autoApproveThreshold(): ?int
    {
        $config = $this->strategy?->config ?? [];
        $value = $config['rules']['autoApproveScore']
            ?? $config['brand_voice']['autoApproveScore']
            ?? null;

        return $value === null ? null : (int) $value;
    }

    public static function recalculateRanks(int $unitId, ?string $batchKey = null): void
    {
        $query = static::where('strategy_id', $unitId)
            ->whereNotNull('ranking_score')
            ->orderByDesc('ranking_score');

        if ($batchKey) {
            $query->where('batch_key', $batchKey);
        }

        $rank = 1;
        foreach ($query->get() as $angle) {
            $angle->updateQuietly(['ranking_rang' => $rank++]);
        }
    }

    public function getScoreColorAttribute(): string
    {
        if ($this->ranking_score === null) return '⚪';
        if ($this->ranking_score >= 10) return '🟢';
        if ($this->ranking_score >= 7) return '🟡';
        return '🔴';
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Angle $model) {
            if (empty($model->id)) {
                $model->id = 'ANG-' . strtoupper(substr(uniqid(), -6));
            }
        });
    }
}
