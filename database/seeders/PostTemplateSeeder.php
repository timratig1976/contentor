<?php

namespace Database\Seeders;

use App\Models\ContentStrategy;
use App\Models\PostTemplate;
use App\Models\Strategy;
use Illuminate\Database\Seeder;

/**
 * Globaler Template-Katalog mit Funnel-Zuordnung.
 * Newsletter-Formate konsolidiert: bk + acquisition → newsletter.
 */
class PostTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // ─── LinkedIn Post ───────────────────────────────────────────────
            [
                'name' => 'Contrarian Take',
                'format' => 'linkedin_post',
                'funnel_stages' => ['tofu'],
                'structure' => "These gegen den Mainstream (max 8 Wörter)\nWarum der Mainstream irrt\nBeleg / Mechanismus\nWas das für den Leser heißt\nSoft CTA",
                'example' => 'CRM-Automatisierung macht euren Vertrieb schlechter. Weil sie Prozesse optimiert, die gar nicht funktionieren. Erst Struktur, dann Automatisierung. Sonst digitalisiert ihr nur Chaos.',
                'best_for' => ['B2B-2', 'B2B-3'],
                'description' => 'Gegen den Mainstream — alle sagen X, Realität ist Y. Provoziert, weckt Neugier. Gut für kalte Zielgruppen, die dich noch nicht kennen.',
            ],
            [
                'name' => 'Data Drop',
                'format' => 'linkedin_post',
                'funnel_stages' => ['tofu'],
                'structure' => "Überraschende Zahl / Fakt\nWarum das überrascht\nMechanismus dahinter\nKonsequenz für den Leser\nSoft CTA",
                'example' => '73% der Forecasts liegen daneben. Nicht wegen schlechter Sales-Leute, sondern wegen fehlender Datenhygiene. Wer seine Pipeline nicht pflegt, steuert blind.',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Überraschende Zahl + Einordnung. Stoppt den Scroll-Fluss sofort. Ideal für ToFu, wenn du mit einem Fakt überraschen kannst.',
            ],
            [
                'name' => 'Mistake Post',
                'format' => 'linkedin_post',
                'funnel_stages' => ['mofu'],
                'structure' => "Fehler 1 + Folge\nFehler 2 + Folge\nFehler 3 + Folge\nWie man es richtig macht\nSoft CTA",
                'example' => '3 Fehler bei der HubSpot-Property-Hygiene: 1. Pflichtfelder nicht gesetzt. 2. Keine Pipeline-Stages definiert. 3. Datenqualität nie geprüft. Die Lösung: Einmal Setup, wöchentliches Review.',
                'best_for' => ['B2B-3', 'B2B-1'],
                'description' => 'Die N häufigsten Fehler bei X. Zeigt Schmerz + Mechanismus. Ideal für MoFu: Zielgruppe kennt das Problem schon, sucht Lösung.',
            ],
            [
                'name' => 'Framework / Modell',
                'format' => 'linkedin_post',
                'funnel_stages' => ['mofu'],
                'structure' => "Problem-Skizze\nModell-Übersicht (3 Stufen)\nStufe 1 …\nStufe 2 …\nStufe 3 …\nErgebnis bei Anwendung\nSoft CTA",
                'example' => 'Unser 3-Stufen-Modell für Datenhygiene: 1. Audit — was ist der Ist-Zustand? 2. Struktur — welche Felder brauchen wir wirklich? 3. Rhythmus — wer prüft wann? Ergebnis: Forecast-Sicherheit in 4 Wochen.',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Eigenes Denkmodell teilen. Baut Autorität auf, zeigt Methodik. Gut für MoFu-Zielgruppen, die dich schon kennen und Tiefe suchen.',
            ],
            [
                'name' => 'Storytelling Post',
                'format' => 'linkedin_post',
                'funnel_stages' => ['mofu'],
                'structure' => "Hook (emotional / neugierig)\nAusgangssituation / Problem\nWendepunkt (Erkenntnis)\nLösung / Learnings\nCTA",
                'example' => 'Letzte Woche saß ich mit einem CEO zusammen, der nicht wusste, wo seine Deals gerade stehen. Wir haben in 2 Stunden seine Pipeline aufgeräumt. Ergebnis: 30% mehr Sichtbarkeit.',
                'best_for' => ['B2B-1', 'B2B-3'],
                'description' => 'Persönliche Geschichte mit Lerneffekt. Emotionaler als andere Formate, baut Vertrauen auf. Ideal für warme Zielgruppen (MoFu).',
            ],
            [
                'name' => 'Listicle',
                'format' => 'linkedin_post',
                'funnel_stages' => ['tofu', 'mofu'],
                'structure' => "Hook (Zahl + Thema)\nPunkt 1\nPunkt 2\nPunkt 3\nBonus / Fazit\nCTA",
                'example' => '5 Dinge, die wir von unseren besten Kunden über CRM gelernt haben: 1. Sie pflegen Daten täglich. 2. Sie haben Property-Regeln. 3. Sie forecasten konservativ. 4. Sie reviewen wöchentlich. 5. Sie haben einen Plan B.',
                'best_for' => ['B2B-1', 'B2B-2', 'B2B-3'],
                'description' => 'Aufzählung mit Mehrwert, leicht konsumierbar. Funktioniert auf ToFu und MoFu — breite Zielgruppe, niedrige Einstiegshürde.',
            ],
            [
                'name' => 'Question Post',
                'format' => 'linkedin_post',
                'funnel_stages' => ['tofu'],
                'structure' => "Frage (provokant / neugierig)\nKontext (warum die Frage)\nEigene Einschätzung\nDiskussionsaufforderung",
                'example' => 'Warum haben die meisten Unternehmen kein Pipeline-Review? Weil es weh tut, die Wahrheit zu sehen. Aber genau das ist der erste Schritt zur Besserung. Wie haltet ihr es?',
                'best_for' => ['B2B-1', 'B2B-2', 'B2B-3'],
                'description' => 'Frage ans Netzwerk — hohe Engagement-Rate. Erzeugt Sichtbarkeit durch Kommentare. Klassisches ToFu-Format.',
            ],
            // ─── Ad Copy ─────────────────────────────────────────────────────
            [
                'name' => 'Ad Copy — Schmerz',
                'format' => 'ad_copy',
                'funnel_stages' => ['tofu', 'mofu'],
                'structure' => "Primary Text (max 125 Zeichen)\nHeadline (max 40 Zeichen)\nDescription\nCTA",
                'example' => 'Primary: Forecast daneben? Vielleicht liegt es nicht am Vertrieb. Headline: Kostenloses Pipeline-Audit',
                'best_for' => ['B2B-2', 'B2B-3'],
                'description' => 'Pain-Frame: zeigt das Problem, bietet Ausweg. Stark für ToFu/MoFu-Ads, wenn der Schmerz bekannt ist.',
            ],
            [
                'name' => 'Ad Copy — Gain',
                'format' => 'ad_copy',
                'funnel_stages' => ['mofu', 'bofu'],
                'structure' => "Primary Text (max 125 Zeichen)\nHeadline (max 40 Zeichen)\nDescription\nCTA",
                'example' => 'Primary: Planbares Wachstum beginnt mit einem sauberen CRM. Headline: Zur kostenlosen Demo',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Gain-Frame: zeigt den Outcome. Besser für warme Zielgruppen (MoFu/BoFu), die schon wissen, was sie wollen.',
            ],
            // ─── Newsletter ───────────────────────────────────────────────────
            [
                'name' => 'Newsletter — Akquise',
                'format' => 'newsletter',
                'funnel_stages' => ['tofu', 'mofu'],
                'structure' => "Betreff (max 50 Zeichen, Neugier wecken)\nPreview-Text (Ergänzung, max 90 Zeichen)\nEinstieg: Problem / Thesen-Eröffnung\nHauptteil: Problem → Lösung → Beweis\nCTA (ein klarer Link)",
                'example' => 'Betreff: Warum 60% der Forecasts falsch sind',
                'best_for' => ['B2B-1', 'B2B-2', 'B2B-3'],
                'description' => 'Für neue Abonnenten / Lead-Generierung. Überzeugend, Problem-fokussiert. Ziel: Klick auf den CTA.',
            ],
            [
                'name' => 'Newsletter — Bestandskunden',
                'format' => 'newsletter',
                'funnel_stages' => ['mofu', 'bofu'],
                'structure' => "Betreff (persönlich, max 50 Zeichen)\nEinleitung (persönliche Ansprache, 1-2 Sätze)\nHauptteil (1-2 konkrete, umsetzbare Punkte)\nNext Step (Handlungsaufforderung, nicht verkäuferisch)\nSign-off",
                'example' => 'Betreff: Datenhygiene in 15 Minuten — diese Woche',
                'best_for' => ['B2B-1', 'B2B-2', 'B2B-3'],
                'description' => 'Für bestehende Kunden / warme Kontakte. Persönlich, nah, konkret. Weniger Überzeugung, mehr Mehrwert und Bindung.',
            ],
            // ─── Landing Page ─────────────────────────────────────────────────
            [
                'name' => 'Landing Page Headline',
                'format' => 'landing_page_headlines',
                'funnel_stages' => ['bofu'],
                'structure' => "Hero Headline (max 20 Wörter)\nSub-Headline (max 30 Wörter)\n3 Bullet Points (je 1 Zeile)\nCTA-Button-Text (2-4 Wörter)",
                'example' => 'Headline: Schluss mit Blindflug im Forecast',
                'best_for' => ['B2B-1', 'B2B-2'],
                'description' => 'Konversionsfokus, kein Storytelling. Klassisches BoFu-Element: Besucher kennen das Problem, entscheiden sich gerade.',
            ],
        ];

        foreach ($templates as $tpl) {
            PostTemplate::updateOrCreate(
                ['name' => $tpl['name']],
                array_merge($tpl, ['source' => 'builtin', 'active' => true])
            );
        }

        // Newsletter-Format: alte bk/acquisition-Einträge umbenennen
        PostTemplate::whereIn('format', ['newsletter_bk', 'newsletter_acquisition'])->delete();

        // Strategien: alle Templates auswählen falls noch leer
        $allIds = PostTemplate::pluck('id')->all();
        foreach (Strategy::all() as $strategy) {
            $cs = ContentStrategy::where('strategy_id', $strategy->id)
                ->where('key', 'post_templates')->first();
            if (! $cs || empty($cs->content['selected'])) {
                ContentStrategy::updateOrCreate(
                    ['strategy_id' => $strategy->id, 'key' => 'post_templates'],
                    ['content' => ['selected' => $allIds], 'version' => ($cs?->version ?? 0) + 1]
                );
            }
        }

        $this->command->info(PostTemplate::count() . ' Templates im Katalog, ' . Strategy::count() . ' Strategien.');
    }
}
