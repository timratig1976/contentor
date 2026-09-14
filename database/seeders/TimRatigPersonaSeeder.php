<?php

namespace Database\Seeders;

use App\Models\Persona;
use Illuminate\Database\Seeder;

class TimRatigPersonaSeeder extends Seeder
{
    /**
     * Anlage der Persona „Tim Ratig" — Gründer-Stil für viscale-Content.
     * Idempotent: läuft über name-Update, dupliziert nicht.
     */
    public function run(): void
    {
        $persona = Persona::updateOrCreate(
            ['name' => 'Tim Ratig'],
            [
                'role' => 'Gründer & Geschäftsführer (viscale)',
                'voice' => 'Trocken-direkt, leicht selbstironisch. Gründer, der das Problem selbst erlebt hat — nicht Blogger oder Berater von außen. Kurze, harte Sätze gemischt mit einem längeren — keine gleichförmige Aufzählungs-Kadenz. Mindestens ein spezifisches Detail, eine Zahl oder eine echte Situation pro Post — keine generischen Platzhalter-Namen (kein „Thomas“, „Anna“ etc. als austauschbare Beispielperson).',
                'perspective' => 'ich',
                'tonality' => [
                    'label' => 'Tim-Ratig-Gründerstil',
                    'stil' => 'trocken-direkt, leicht selbstironisch, erlebte Praxis',
                    'perspektive' => 'Ich-Form, wo passend („bei uns“, „ich hab gesehen“) statt neutraler Lehrbuch-Aussagen',
                    'satzrhythmus' => 'kurze, harte Sätze mischen mit einem längeren — keine gleichförmige Aufzählungs-Kadenz',
                    'konkretion' => 'mind. 1 spezifisches Detail/Zahl/Situation statt generischer Platzhalter-Namen',
                    'verbote' => [
                        'nachhaltig', 'ganzheitlich', 'auf Augenhöhe',
                        'Lehrbuch-Ton', 'Worthülsen', '08/15-Business-Sprache',
                    ],
                ],
                'positioning' => 'Gründer eines CRM/RevOps-Beratungshauses für den Mittelstand. Kennt Blindflug im Forecast, versickernde Leads und Vertriebs-Chaos aus eigener Praxis — nicht aus Whitepapers.',
                'core_statements' => [
                    'Vertrieb scheitert an Struktur, nicht an Menschen — ich hab beides gesehen, bei uns selbst zuerst.',
                    'Ein sauberes CRM ist kein IT-Projekt, sondern Chefsache.',
                    'Wer den Forecast nicht erklären kann, rät nur mit Excel.',
                ],
                'forbidden_words' => [
                    '!', 'nachhaltig', 'ganzheitlich', 'auf Augenhöhe',
                    'revolutionär', 'beste', 'einzigartig', 'wir freuen uns',
                ],
                'emoji_usage' => 'none',
                'active' => true,
            ]
        );

        // viscale (strategy_id 1) zuordnen — NICHT als Default, die bestehende
        // RevOps-Architekt-Persona bleibt der Standard-Produktionsstil.
        $viscale = \App\Models\Strategy::where('key', 'viscale')->first();
        if ($viscale) {
            \DB::table('persona_strategy_map')->updateOrInsert(
                ['persona_id' => $persona->id, 'strategy_id' => $viscale->id],
                ['is_default' => 0, 'mapped_angles' => null, 'mapped_topics' => null,
                 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command?->info('✅ Persona „' . $persona->name . '“ angelegt/aktualisiert (ID ' . $persona->id . ', Strategie: viscale).');
    }
}
