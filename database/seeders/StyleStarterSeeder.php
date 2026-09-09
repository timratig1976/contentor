<?php

namespace Database\Seeders;

use App\Models\ContentStrategy;
use App\Models\Persona;
use App\Models\Strategy;
use Illuminate\Database\Seeder;

/**
 * Starterdaten für den Stil-Layer der Content-Generierung.
 *
 * Ohne Brand Voice + Persona ist der STIL-LAYER des Production-Prompts
 * leer und das LLM schreibt generisch. Dieser Seeder legt eine auf die
 * viscale-Strategie-Regeln abgestimmte Brand Voice sowie eine
 * Default-Persona an (idempotent — überschreibt keine manuell
 * angepassten Inhalte).
 */
class StyleStarterSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedViscaleBrandVoice();
        $this->seedPersonas();
    }

    private function seedViscaleBrandVoice(): void
    {
        $strategy = Strategy::where('key', 'viscale')->first();
        if (! $strategy) {
            return;
        }

        // Nur anlegen, wenn noch keine Brand Voice existiert (manuelle Pflege gewinnt)
        $exists = ContentStrategy::where('strategy_id', $strategy->id)
            ->where('key', 'brand_voice')
            ->exists();
        if ($exists) {
            return;
        }

        ContentStrategy::create([
            'strategy_id' => $strategy->id,
            'key' => 'brand_voice',
            'version' => 1,
            'content' => [
                'personality' => 'Erfahrener RevOps-Consultant: analytisch, praxisnah, meinungsstark. Spricht aus Delivery-Erfahrung mit HubSpot-Projekten im Mittelstand — nicht aus dem Lehrbuch.',
                'tone' => 'direkt, klar, partnerschaftlich auf Augenhöhe. Provokant in der These, konstruktiv in der Lösung. Kein Marketing-Sprech, keine Superlative, kein Verkaufsdruck.',
                'must' => [
                    'Konkrete Mechanismen statt allgemeiner Floskeln erklären (wie etwas funktioniert, nicht dass es wichtig ist)',
                    'Belege aus der Praxis nennen: Zahlen, typische Fehlerbilder, HubSpot-Spezifika (Properties, Pipelines, Workflows)',
                    'Jeden Post mit einer klaren Konsequenz oder einem nächsten Schritt für den Leser enden lassen',
                    'Du-Ansprache (plural: "ihr/euch") für Teams und Entscheider',
                ],
                'never' => [
                    'Ausrufezeichen',
                    'Superlative und Hype-Wörter (beste, einzigartig, revolutionär)',
                    'Verkaufsformeln ("bucht jetzt", "demo anfragen", "wir freuen uns")',
                    'Generische Berater-Sätze ohne konkreten Bezug zu CRM/RevOps',
                    'Probleme nur benennen, ohne Mechanismus oder Lösung zu zeigen',
                ],
            ],
        ]);
    }

    private function seedPersonas(): void
    {
        foreach (['viscale', 'vitalents'] as $key) {
            $strategy = Strategy::where('key', $key)->first();
            if (! $strategy) {
                continue;
            }

            $alreadyMapped = $strategy->personas()->exists();
            if ($alreadyMapped) {
                continue;
            }

            if ($key === 'viscale') {
                $persona = Persona::firstOrCreate(
                    ['name' => 'RevOps-Architekt'],
                    [
                        'role' => 'Senior RevOps Consultant & HubSpot-Architekt',
                        'voice' => 'Analytisch-präzise mit Praxis-Kante. Kurze, direkte Sätze. Erst das Problem benennen, dann den Mechanismus erklären, dann die Konsequenz. Du-Form (ihr/euch). Keine Buzzwords ohne Substanz.',
                        'perspective' => 'wir',
                        'emoji_usage' => 'none',
                        'max_sentence_length' => 18,
                        'core_statements' => [
                            'Mehr Leads lösen kein Strukturproblem — ohne saubere Pipeline-Architektur versickert jede Nachfrage.',
                            'Forecast-Sicherheit ist kein Tool-Feature, sondern das Ergebnis von Datenhygiene und Führungsritualen.',
                            'CRM-Automatisierung auf chaotischen Prozessen skaliert nur das Chaos.',
                        ],
                        'tonality' => [
                            'label' => 'viscale-Tonalitätsregeln',
                            'stil' => 'direkt, mechanistisch, praxisbelegt',
                            'verbote' => ['!', 'beste', 'einzigartig', 'revolutionär', 'bucht jetzt', 'demo anfragen'],
                        ],
                        'positioning' => 'Der RevOps-Architekt für den Mittelstand: HubSpot-Systeme, die Forecast-Sicherheit und skalierbaren Vertrieb liefern.',
                        'content_attributes' => [
                            'keywords' => ['Pipeline-Hygiene', 'Forecast', 'Property-Regeln', 'RevOps', 'HubSpot-Architektur', 'Datenqualität', 'Pipeline-Review', 'Vertriebssystem'],
                            'themenfokus' => ['Blindflug im Forecast', 'Vertrieb hängt an Personen', 'Datenchaos', 'Leads versickern'],
                        ],
                        'forbidden_words' => ['!', 'beste', 'einzigartig', 'revolutionär', 'wir freuen uns', 'bucht jetzt'],
                        'active' => true,
                    ]
                );
            } else {
                $persona = Persona::firstOrCreate(
                    ['name' => 'Klinik-Personalflüsterer'],
                    [
                        'role' => 'Recruiting-Experte für Klinik & Pflege',
                        'voice' => 'Empathisch, aber klar. Versteht den Alltag in Klinik und Pflege — Schichtdienst, Fachkräftemangel, Zeitdruck. Konkrete Lösungen statt Recruiting-Floskeln. Du-Form (ihr/euch).',
                        'perspective' => 'wir',
                        'emoji_usage' => 'light',
                        'max_sentence_length' => 20,
                        'core_statements' => [
                            'Fachkräftemangel in der Pflege ist kein Bewerber-Problem, sondern ein Prozess-Problem.',
                            'Kandidaten springen nicht wegen des Gehalts ab — sie springen ab, weil niemand sich meldet.',
                            'Employer Branding ohne Beweislast ist Werbung, die niemand glaubt.',
                        ],
                        'tonality' => [
                            'label' => 'vitalents-Tonalitätsregeln',
                            'stil' => 'empathisch, klar, lösungsorientiert',
                            'verbote' => ['!', 'beste', 'einzigartig', 'revolutionär'],
                        ],
                        'positioning' => 'Systematisches Recruiting für Klinik und Pflege: Prozesse, die Kandidaten halten statt verlieren.',
                        'content_attributes' => [
                            'keywords' => ['Pflegerecruiting', 'Fachkräftemangel', 'Klinik', 'Bewerberprozess', 'ATS', 'Employer Branding', 'Schichtdienst'],
                            'themenfokus' => ['Fachkräftemangel in Klinik und Pflege', 'Recruiting-Prozesse ohne System', 'Kandidaten springen ab'],
                        ],
                        'forbidden_words' => ['!', 'beste', 'einzigartig', 'revolutionär'],
                        'active' => true,
                    ]
                );
            }

            // Persona der Strategie als Default zuordnen
            $persona->strategies()->syncWithoutDetaching([
                $strategy->id => ['is_default' => true],
            ]);

            $this->command->info("Persona '{$persona->name}' für {$key} angelegt + zugeordnet.");
        }
    }
}
