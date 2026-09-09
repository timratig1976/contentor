<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qualitäts-Gate: LLM-Score + Regel-Funde pro Content-Item.
 *
 * quality_score:    0–10 vom Review-Modell (null = nicht bewertet)
 * quality_comment:  Kurzbegründung des Review-Modells
 * quality_flags:    JSON — Regel-Funde (tone_violations, missing_cta,
 *                   length_violation) + fix_rounds (Auto-Korrektur-Runden)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->nullable()->after('variant_pattern');
            $table->text('quality_comment')->nullable()->after('quality_score');
            $table->json('quality_flags')->nullable()->after('quality_comment');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn(['quality_score', 'quality_comment', 'quality_flags']);
        });
    }
};
