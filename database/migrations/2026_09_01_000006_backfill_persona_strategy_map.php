<?php

use App\Models\Persona;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bestehende Personas, die noch ein strategy_id haben, ins Mapping übernehmen.
        // Die bisherige Strategie wird als Default-Mapping (is_default = true) gesetzt.
        foreach (Persona::whereNotNull('strategy_id')->get() as $persona) {
            $exists = DB::table('persona_strategy_map')
                ->where('persona_id', $persona->id)
                ->where('strategy_id', $persona->strategy_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('persona_strategy_map')->insert([
                'persona_id'    => $persona->id,
                'strategy_id'   => $persona->strategy_id,
                'mapped_angles' => $persona->angles ? json_encode($persona->angles) : null,
                'mapped_topics' => $persona->topics ? json_encode($persona->topics) : null,
                'is_default'    => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Kein Zurückführen nötig — strategy_id bleibt als Legacy-Feld erhalten.
    }
};
