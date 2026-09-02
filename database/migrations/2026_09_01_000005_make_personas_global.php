<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persona wird global: strategy_id wird optional (verbleibt als Legacy-Feld)
        Schema::table('personas', function (Blueprint $table) {
            $table->string('strategy_id')->nullable()->change();
        });

        // Mapping: eine Persona kann mehreren Strategien zugeordnet sein.
        // Pro Strategie: die relevanten Angles + Themen-Cluster.
        Schema::create('persona_strategy_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            // "mapped_"-Präfix: vermeidet Kollision mit Strategy::angles() / Persona::topics()
            $table->jsonb('mapped_angles')->nullable();       // pro Strategie relevante Angles
            $table->jsonb('mapped_topics')->nullable();       // pro Strategie relevante Themen
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['persona_id', 'strategy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_strategy_map');
        Schema::table('personas', function (Blueprint $table) {
            $table->string('strategy_id')->nullable(false)->change();
        });
    }
};
