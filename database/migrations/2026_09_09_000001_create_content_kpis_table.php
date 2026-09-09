<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KPI-Erfassung für veröffentlichte Content-Items.
 *
 * Ermöglicht die Lernschleife: Nach dem Publishing werden echte
 * Performance-Zahlen erfasst und aggregiert ausgewertet (welches
 * Format × Pattern × ICP × Statement-Typ funktioniert am besten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->onDelete('cascade');
            $table->string('content_item_id');
            $table->foreign('content_item_id')->references('id')->on('content_items')->onDelete('cascade');

            // Zeitpunkt der Messung (KPIs entwickeln sich über Zeit)
            $table->date('measured_at');

            // ── Reichweite & Interaktion ─────────────────────
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('reach')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('shares')->default(0);

            // ── Conversion ───────────────────────────────────
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('revenue_eur', 12, 2)->nullable();

            // ── Newsletter-/Blog-spezifisch ──────────────────
            $table->decimal('open_rate', 5, 2)->nullable();   // %
            $table->decimal('click_rate', 5, 2)->nullable();   // %

            // Abgerechnete Metrik: welche Zahl zählt für diese Einheit am meisten?
            $table->string('primary_metric')->nullable();      // z. B. 'leads', 'clicks'
            $table->decimal('primary_value', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Ein Eintrag pro Item + Messdatum (Update-statt-Duplikat-Logik)
            $table->unique(['content_item_id', 'measured_at']);
            $table->index(['strategy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_kpis');
    }
};
