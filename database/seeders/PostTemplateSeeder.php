<?php

namespace Database\Seeders;

use App\Models\ContentStrategy;
use App\Models\Strategy;
use Illuminate\Database\Seeder;

class PostTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Contrarian Take',
                'format' => 'linkedin_post',
                'structure' => "These gegen den Mainstream (max 8 Wörter)\nWarum der Mainstream irrt\nBeleg / Mechanismus\nWas das für den Leser heißt\nSoft CTA",
                'example' => 'CRM-Automatisierung macht euren Vertrieb schlechter. Weil sie Prozesse optimiert, die gar nicht funktionieren. Erst Struktur, dann Automatisierung. Sonst digitalisiert ihr nur Chaos.',
                'best_for' => ['B2B-2', 'B2B-3'],
                'description' => 'Gegen den Mainstream — alle sagen X, Realität ist Y',
            ],
            [
                'name' => 'Data Drop',
                'format' => 'linkedin_post',
                'structure' => "Überraschende Zahl / Fakt\nWarum das überrascht\nMechanismus dahinter\nKonsequenz für den Leser\nSoft CTA",
                'example' => '73% der Forecasts liegen daneben. Nicht wegen schlechter Sales-Leute, sondern wegen fehlender Datenhygiene. Wer seine Pipeline nicht pflegt, steuert blind.',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Überraschende Zahl + Einordnung',
            ],
            [
                'name' => 'Mistake Post',
                'format' => 'linkedin_post',
                'structure' => "Fehler 1 + Folge\nFehler 2 + Folge\nFehler 3 + Folge\nWie man es richtig macht\nSoft CTA",
                'example' => '3 Fehler bei der HubSpot-Property-Hygiene: 1. Pflichtfelder nicht gesetzt. 2. Keine Pipeline-Stages definiert. 3. Datenqualität nie geprüft. Die Lösung: Einmal Setup, wöchentliches Review.',
                'best_for' => ['B2B-3', 'BK'],
                'description' => 'Die N häufigsten Fehler bei X',
            ],
            [
                'name' => 'Framework / Modell',
                'format' => 'linkedin_post',
                'structure' => "Problem-Skizze\nModell-Übersicht (3 Stufen)\nStufe 1 …\nStufe 2 …\nStufe 3 …\nErgebnis bei Anwendung\nSoft CTA",
                'example' => 'Unser 3-Stufen-Modell für Datenhygiene: 1. Audit — was ist der Ist-Zustand? 2. Struktur — welche Felder brauchen wir wirklich? 3. Rhythmus — wer prüft wann? Ergebnis: Forecast-Sicherheit in 4 Wochen.',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Eigenes Denkmodell oder Methode teilen',
            ],
            [
                'name' => 'Storytelling Post',
                'format' => 'linkedin_post',
                'structure' => "Hook (emotional / neugierig)\nAusgangssituation / Problem\nWendepunkt (Erkenntnis)\nLösung / Learnings\nCTA",
                'example' => 'Letzte Woche saß ich mit einem CEO zusammen, der nicht wusste, wo seine Deals gerade stehen. Wir haben in 2 Stunden seine Pipeline aufgeräumt. Ergebnis: 30% mehr Sichtbarkeit.',
                'best_for' => ['B2B-1', 'B2B-3'],
                'description' => 'Persönliche Geschichte mit Lerneffekt',
            ],
            [
                'name' => 'Listicle',
                'format' => 'linkedin_post',
                'structure' => "Hook (Zahl + Thema)\nPunkt 1\nPunkt 2\nPunkt 3\nBonus / Fazit\nCTA",
                'example' => '5 Dinge, die wir von unseren besten Kunden über CRM gelernt haben: 1. Sie pflegen Daten täglich. 2. Sie haben Property-Regeln. 3. Sie forecasten konservativ. 4. Sie reviewen wöchentlich. 5. Sie haben einen Plan B.',
                'best_for' => ['B2B-1', 'B2B-2', 'B2B-3'],
                'description' => 'Aufzählung mit Mehrwert, leicht konsumierbar',
            ],
            [
                'name' => 'Question Post',
                'format' => 'linkedin_post',
                'structure' => "Frage (provokant / neugierig)\nKontext (warum die Frage)\nEigene Einschätzung\nDiskussionsaufforderung",
                'example' => 'Warum haben die meisten Unternehmen kein Pipeline-Review? Weil es weh tut, die Wahrheit zu sehen. Aber genau das ist der erste Schritt zur Besserung. Wie haltet ihr es?',
                'best_for' => ['B2B-1', 'B2B-2', 'BK'],
                'description' => 'Frage ans Netzwerk, hohe Engagement-Rate',
            ],
            [
                'name' => 'Ad Copy — Schmerz',
                'format' => 'ad_copy',
                'structure' => "Primary Text (max 125 Zeichen)\nHeadline (max 40 Zeichen)\nDescription\nCTA",
                'example' => 'Primary: Forecast daneben? Vielleicht liegt es nicht am Vertrieb. Headline: Kostenloses Pipeline-Audit',
                'best_for' => ['B2B-2', 'B2B-3'],
                'description' => 'Schmerz-getriebene Anzeige für B2B',
            ],
            [
                'name' => 'Ad Copy — Gain',
                'format' => 'ad_copy',
                'structure' => "Primary Text (max 125 Zeichen)\nHeadline (max 40 Zeichen)\nDescription\nCTA",
                'example' => 'Primary: Planbares Wachstum beginnt mit einem sauberen CRM. Headline: Zur kostenlosen Demo',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Gain-getriebene Anzeige, Outcome-Fokus',
            ],
            [
                'name' => 'Newsletter BK',
                'format' => 'newsletter_bk',
                'structure' => "Betreff (max 50 Zeichen)\nPreview-Text\nEinleitung (persönlich)\nHauptteil (1-2 konkrete Punkte)\nNext Step (Handlungsaufforderung)\nSign-off",
                'example' => 'Betreff: Datenhygiene in 15 Minuten',
                'best_for' => ['BK', 'UNI'],
                'description' => 'Bestandskunden-Newsletter mit konkreten Handlungsschritten',
            ],
            [
                'name' => 'Landing Page Headline',
                'format' => 'landing_page_headlines',
                'structure' => "Hero Headline (max 20 Wörter)\nSub-Headline (max 30 Wörter)\n3 Bullet Points\nCTA-Button-Text",
                'example' => 'Headline: Schluss mit Blindflug im Forecast',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Konversions-starke Landing Page',
            ],
        ];

        foreach (Strategy::all() as $strategy) {
            $content = ContentStrategy::where('strategy_id', $strategy->id)
                ->where('key', 'post_templates')
                ->first();

            $existing = $content ? ($content->content['templates'] ?? []) : [];

            $existingNames = collect($existing)->pluck('name')->map(fn ($n) => strtolower($n))->toArray();

            foreach ($templates as $tpl) {
                if (!in_array(strtolower($tpl['name']), $existingNames)) {
                    $existing[] = $tpl;
                }
            }

            ContentStrategy::updateOrCreate(
                ['strategy_id' => $strategy->id, 'key' => 'post_templates'],
                ['content' => ['templates' => $existing], 'version' => ($content?->version ?? 0) + 1]
            );
        }

        $this->command->info(count($templates) . ' Post-Templates in ' . Strategy::count() . ' Strategien geseedet.');
    }
}