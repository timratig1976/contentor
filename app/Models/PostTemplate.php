<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Globaler Template-Katalog (Post-Muster wie "Data Drop", "Mistake Post").
 * Strategie-unabhängig — jede Strategie wählt aus diesem Katalog aus,
 * welche Templates für sie aktiv sind (siehe ContentStrategy key=post_templates).
 */
class PostTemplate extends Model
{
    protected $fillable = [
        'name', 'format', 'description', 'structure', 'example', 'best_for', 'source', 'active',
    ];

    protected $casts = [
        'best_for' => 'array',
        'funnel_stages' => 'array',
        'active' => 'boolean',
    ];

    public const FORMATS = [
        'linkedin_post' => 'LinkedIn Post',
        'ad_copy' => 'Ad Copy',
        'newsletter' => 'Newsletter',
        'landing_page_headlines' => 'Landing Page',
        'blog_post' => 'Blog Post',
    ];

    /** Lesbare Funnel-Label für die UI */
    public const FUNNEL_LABELS = [
        'tofu'  => 'ToFu — Awareness',
        'mofu'  => 'MoFu — Consideration',
        'bofu'  => 'BoFu — Decision',
        'all'   => 'Alle Stufen',
    ];
}
