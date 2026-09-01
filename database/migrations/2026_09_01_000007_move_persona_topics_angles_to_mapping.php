<?php

use App\Models\Persona;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Globale topics/angles in das (Default-)Mapping übernehmen
        foreach (Persona::with('strategies')->get() as $persona) {
            $hasTopics = !empty($persona->topics);
            $hasAngles = !empty($persona->angles);
            if (!$hasTopics && !$hasAngles) {
                continue;
            }

            $targets = $persona->strategies->pluck('id')->all();
            // Kein Mapping vorhanden → Legacy-Strategie als Ziel nehmen
            if (empty($targets) && $persona->strategy_id) {
                $targets = [$persona->strategy_id];
            }

            foreach ($targets as $strategyId) {
                DB::table('persona_strategy_map')
                    ->where('persona_id', $persona->id)
                    ->where('strategy_id', $strategyId)
                    ->update([
                        'mapped_topics' => $hasTopics ? json_encode($persona->topics) : null,
                        'mapped_angles' => $hasAngles ? json_encode($persona->angles) : null,
                        'updated_at'    => now(),
                    ]);
            }
        }

        // 2) Globale Spalten entfernen (Persona = Identität nur)
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn(['topics', 'angles']);
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->jsonb('topics')->nullable()->after('positioning');
            $table->jsonb('angles')->nullable()->after('topics');
        });
    }
};
