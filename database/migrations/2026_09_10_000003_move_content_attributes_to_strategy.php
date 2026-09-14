<?php

use App\Models\ContentStrategy;
use App\Models\Persona;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * content_attributes lebt jetzt in der Strategie (ContentStrategy key='content_strategy').
     * - keywords/themenfokus der Personas werden pro zugeordneter Strategie dorthin migriert
     * - maxLength/formats/tone waren tote Felder (nie gelesen) → entfallen
     */
    public function up(): void
    {
        // 1) Vorhandene Persona-Keywords in die zugeordneten Strategien übernehmen
        foreach (Persona::whereNotNull('content_attributes')->get() as $persona) {
            $attrs = $persona->content_attributes ?: [];
            $keywords = array_values(array_filter((array) ($attrs['keywords'] ?? [])));
            $topics = array_values(array_filter((array) ($attrs['themenfokus'] ?? [])));
            if (! $keywords && ! $topics) {
                continue;
            }

            $strategyIds = DB::table('persona_strategy_map')
                ->where('persona_id', $persona->id)
                ->pluck('strategy_id');

            foreach ($strategyIds as $strategyId) {
                $cs = ContentStrategy::firstOrNew([
                    'strategy_id' => $strategyId,
                    'key' => 'content_strategy',
                ]);
                $content = $cs->content ?: [];

                $content['keywords'] = array_values(array_unique(array_merge(
                    (array) ($content['keywords'] ?? []),
                    $keywords
                )));
                if ($topics) {
                    $content['topic_focus'] = array_values(array_unique(array_merge(
                        (array) ($content['topic_focus'] ?? []),
                        $topics
                    )));
                }

                $cs->content = $content;
                $cs->save();
            }
        }

        // 2) Spalte entfernen
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('content_attributes');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->json('content_attributes')->nullable()->after('angles');
        });
    }
};
