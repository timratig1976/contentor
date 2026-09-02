<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\Persona;
use App\Models\Source;
use App\Models\Strategy;
use App\Services\ContentRulesService;
use App\Services\EdenAIWebService;
use App\Services\QuickInputAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class QuickInputController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
        private EdenAIWebService $webService,
        private QuickInputAgentService $agentService,
    ) {}

    /**
     * Schnell-Eingabe: Text, PDF oder URL einfügen → Source + optional Angle erstellen.
     * Unterstützt: Blog-Artikel, LinkedIn-Posts, PDFs, URLs, Notizen, Kundenzitate.
     */
    public function store(Request $request)
    {
        // Handle PDF upload
        if ($request->hasFile('file')) {
            return $this->storeFromFile($request);
        }

        $validated = $request->validate([
            'content' => 'required|string|min:10',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'type' => 'nullable|string|in:auto,blog,linkedin,url,interview,note,quote,pdf',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
            'num_angles' => 'nullable|integer|min:1|max:10',
        ]);
        $validated = $request->validate([
            'content' => 'required|string|min:10',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'type' => 'nullable|string|in:auto,blog,linkedin,url,interview,note,quote',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
            'num_angles' => 'nullable|integer|min:1|max:10',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $content = $validated['content'];
        $type = $validated['type'] ?? 'auto';

        // Auto-detect content type
        if ($type === 'auto') {
            $type = $this->detectType($content);
        }

        // If content looks like a URL → scrape it first
        $scrapedUrl = null;
        if ($type === 'url' || preg_match('/^https?:\/\//i', $content) || preg_match('/^[\w-]+\.\w{2,}(\/|$)/i', $content)) {
            $url = preg_match('/^https?:\/\//i', $content) ? $content : 'https://' . $content;
            $scrapedUrl = $url;
            if ($this->webService->configured()) {
                $scraped = $this->webService->scrape($url);
                if ($scraped['success'] && !empty($scraped['content'])) {
                    $content = $this->cleanScrapedContent($scraped['content']);
                    $type = 'url';
                } else {
                    // Scraping failed but we can still store the URL as source
                    $content = $url;
                }
            } else {
                $content = $url;
            }
        }

        // Create source
        $title = $validated['title'] ?? mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : '');
        $source = Source::create([
            'title' => $title,
            'type' => $this->mapTypeToSource($type),
            'strategy_id' => $strategy->id,
            'visibility' => 'intern',
            'batch_key' => $validated['batch_key'] ?? 'quick-' . now()->format('Ymd'),
            'file_ref' => $scrapedUrl ?? null,
        ]);

        $result = [
            'source' => $source->load('strategy'),
            'angles' => [],
            'scraped' => $scrapedUrl !== null,
            'content_length' => mb_strlen($content),
        ];

        // Optionally create angles
        if ($validated['create_angles'] ?? true) {
            $angles = $this->extractAngles($content, $strategy, $source);
            $result['angles'] = $angles;
        }

        if ($request->header('X-Inertia')) {
            return redirect()->route('quellen');
        }

        return response()->json($result, 201);
    }

    /**
     * Handle PDF file upload — extract text and process.
     */
    private function storeFromFile(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,txt,md,doc,docx|max:10240',
            'title' => 'nullable|string|max:255',
            'strategy' => 'required|string|exists:strategies,key',
            'batch_key' => 'nullable|string',
            'create_angles' => 'boolean',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Extract text from file
        if ($extension === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $content = $pdf->getText();
        } else {
            $content = file_get_contents($file->getPathname());
        }

        $content = trim($content);
        if (empty($content)) {
            return response()->json(['error' => 'Kein Text aus der Datei extrahiert.'], 422);
        }

        $title = $validated['title'] ?? $file->getClientOriginalName();

        $source = Source::create([
            'title' => $title,
            'type' => 'pdf',
            'strategy_id' => $strategy->id,
            'visibility' => 'intern',
            'file_ref' => $file->getClientOriginalName(),
            'batch_key' => $validated['batch_key'] ?? 'pdf-' . now()->format('Ymd'),
        ]);

        $result = ['source' => $source->load('strategy'), 'angles' => []];

        if ($validated['create_angles'] ?? true) {
            $angles = $this->extractAngles($content, $strategy, $source);
            $result['angles'] = $angles;
        }

        if ($request->header('X-Inertia')) {
            return redirect()->route('quellen');
        }

        return response()->json($result, 201);
    }

    private function detectType(string $content): string
    {
        // URL detection
        if (preg_match('/^https?:\/\//i', trim($content))) {
            return 'url';
        }
        // LinkedIn post detection (shorter, hashtags, informal)
        if (mb_strlen($content) < 3000 && preg_match('/#\w+/i', $content) && !preg_match('/[.;]{2,}/', $content)) {
            return 'linkedin';
        }
        // Quote detection
        if (preg_match('/["„"]/', $content) && mb_strlen($content) < 500) {
            return 'quote';
        }
        // Blog detection (longer, structured)
        if (mb_strlen($content) > 500) {
            return 'blog';
        }
        return 'note';
    }

    private function mapTypeToSource(string $type): string
    {
        return match ($type) {
            'url' => 'url',
            'blog', 'linkedin' => 'research',
            'quote' => 'interview',
            'note' => 'intern',
            default => 'research',
        };
    }

    private function cleanScrapedContent(string $content): string
    {
        // Markdown-Bilder entfernen: ![alt](url)
        $content = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $content);
        // Markdown-Links zu Text reduzieren: [text](url) → text
        $content = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $content);
        // Bare URLs entfernen
        $content = preg_replace('/https?:\/\/\S+/i', '', $content);
        // HTML-Entities bereinigen
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Markdown-Bold/Italic (**text**, *text*, __text__)
        $content = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $content);
        $content = preg_replace('/(\*|_)(.*?)\1/', '$2', $content);
        // Markdown-Überschriften (#, ##, ###) → Leerzeile
        $content = preg_replace('/^#{1,6}\s+/m', '', $content);
        // Einzelne Sonderzeichen-Zeilen (→, ✓, •, –) bereinigen
        $content = preg_replace('/^[\s→✓•·▸\-–—]+$/m', '', $content);
        // Wiederholte Sonderzeichen (z.B. ✓✓✓, →→→)
        $content = preg_replace('/([^\w\s])\1{2,}/', '$1', $content);

        // Überschrift-artige Kurzzeilen entfernen (z.B. "HEBEL 03", "CRM & Daten", "Marketing")
        $lines = array_map('trim', explode("\n", $content));
        $lines = array_filter($lines, function ($line) {
            if ($line === '') return true;
            if (preg_match('/[.!?…]$/', $line)) return true;   // echter Satz → behalten
            if (mb_strlen($line) > 60) return true;            // zu lang für Überschrift
            if (preg_match('/^HEBEL\s*\d+/i', $line)) return false;
            if (preg_match('/^[A-ZÄÖÜ0-9&\s\-–—·:]+$/u', $line)) return false; // ALL-CAPS-Zeile
            $words = count(preg_split('/\s+/', $line));
            if ($words <= 3 && preg_match('/^[A-ZÄÖÜ]/u', $line)) return false; // Kurz-Label
            return true;
        });
        $content = implode("\n", $lines);

        // Mehrfach-Leerzeilen auf max. 2 begrenzen
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    private function extractAngles(string $content, Strategy $strategy, Source $source): array
    {
        $angles = [];

        // Inhalt bereinigen, dann LLM-extraktion
        $cleanContent = $this->cleanScrapedContent($content);
        $result = $this->agentService->extractAngles($cleanContent, $strategy, 5);

        if ($result['error']) {
            Log::warning("QuickInput LLM fehlgeschlagen: {$result['error']} — Fallback auf Regex-Extraktion.");
            return $this->extractAnglesFallback($cleanContent, $strategy, $source);
        }

        foreach ($result['angles'] as $item) {
            // ICP/Cluster: LLM-Vorschlag primär, Regex-Fallback vom Angle-Text
            $icp = $item['icp'] ?: $this->rulesService->guessIcp($item['angle'], null, $strategy);
            $cluster = null;
            if ($item['pain_cluster']) {
                foreach ($strategy->clusters as $c) {
                    if ($c['code'] === $item['pain_cluster']) { $cluster = $c; break; }
                }
            }
            if (!$cluster) {
                $cluster = $this->rulesService->pickPainCluster($item['angle'], $strategy);
            }
            $painCluster = $cluster ? "{$cluster['code']} · {$cluster['name']}" : null;
            $statementType = $item['statement_type'] ?: $this->rulesService->pickStatementType(null, null, $item['angle']);

            $angle = Angle::create([
                'angle' => $item['angle'],
                'strategy_id' => $strategy->id,
                'source_id' => $source->id,
                'batch_key' => $source->batch_key,
                'icp' => $icp,
                'pain_cluster' => $painCluster,
                'statement_type' => $statementType,
            ]);
            $angles[] = $angle;

            // LLM-Scoring: Angle bewerten + Ranking setzen + ICP/Cluster validieren
            try {
                $score = $this->agentService->scoreAngle($angle->angle, $strategy, $icp, $painCluster);
                if ($score) {
                    $angle->updateQuietly([
                        'r_zielgruppe' => $score['r_zielgruppe'],
                        'r_viscale_fit' => $score['r_viscale_fit'],
                        'r_schaerfe' => $score['r_schaerfe'],
                        'r_timing' => $score['r_timing'],
                    ]);

                    // ICP/Cluster vom LLM validieren lassen -> bei Invalid Regex-Fallback pro Angle
                    $needsUpdate = false;
                    if (!$score['icp_valid']) {
                        $angle->icp = $this->rulesService->guessIcp($angle->angle, null, $strategy);
                        $needsUpdate = true;
                    }
                    if (!$score['cluster_valid']) {
                        $cluster = $this->rulesService->pickPainCluster($angle->angle, $strategy);
                        $angle->pain_cluster = $cluster ? "{$cluster['code']} · {$cluster['name']}" : null;
                        $needsUpdate = true;
                    }
                    if ($needsUpdate) {
                        $angle->saveQuietly();
                    }

                    $angle->updateRanking();
                    $angle->refresh();
                }
            } catch (\Throwable $e) {
                Log::warning("Score für Angle {$angle->id} fehlgeschlagen: {$e->getMessage()}");
            }

            // Embedding + Duplikat-Check (bestehende Infrastruktur)
            try {
                $embeddingService = app(\App\Services\EmbeddingService::class);
                $vector = $embeddingService->embed($angle->angle);
                if ($vector) {
                    \DB::statement("UPDATE angles SET embedding = ?::vector WHERE id = ?", [
                        '[' . implode(',', $vector) . ']', $angle->id,
                    ]);
                    $similar = $embeddingService->findMostSimilar($vector, $strategy->id, $angle->id);
                    if ($similar) {
                        $angle->updateQuietly([
                            'duplicate_of_id' => $similar['id'],
                            'similarity_score' => $similar['similarity'],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Embedding ist optional — kein Grund abzubrechen
            }
        }

        return $angles;
    }

    /**
     * Fallback: Regex-basierte Extraktion, falls LLM nicht verfügbar.
     */
    private function extractAnglesFallback(string $content, Strategy $strategy, Source $source): array
    {
        $angles = [];

        // Inhalt erst bereinigen
        $cleanContent = $this->cleanScrapedContent($content);

        // Sätze splitten
        $sentences = preg_split('/(?<=[.!?])\s+/', $cleanContent, -1, PREG_SPLIT_NO_EMPTY);

        $scored = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            // Qualitätsfilter: Satz muss echten Inhalt haben
            // Zu kurz/lang → überspringen
            $len = mb_strlen($sentence);
            if ($len < 25 || $len > 220) continue;
            // Immer noch URL-Reste → überspringen
            if (preg_match('/https?:|www\.|\.png|\.jpg|\.svg|\.jpeg/i', $sentence)) continue;
            // Reine Navigations-/UI-Texte → überspringen
            if (preg_match('/^(Menü|Navigation|Cookie|Impressum|Datenschutz|Kontakt|Home|Newsletter abonnieren|Jetzt buchen)/i', $sentence)) continue;
            // Muss mindestens 4 echte Wörter enthalten
            if (str_word_count($sentence) < 4) continue;

            $score = 0;
            $lower = mb_strtolower($sentence);

            // Meinungsstarke / provokante Aussagen
            if (preg_match('/ist|sind|sollte|müssen|kann nicht|ohne|nie|immer|falsch|richtig|problem|lösung|fehler|scheitern|versagen/i', $sentence)) $score += 2;
            if (preg_match('/die meisten|alle|niemand|jeder|kein einziger|zu viele/i', $lower)) $score += 2;
            // Metriken = sehr wertvoll
            if (preg_match('/\d+\s*%|\d+x|\d+\s*(€|\$|prozent|fach|mal|stunden|tage|monate)/i', $sentence)) $score += 3;
            // Kontrastierende Aussagen (aber/statt/obwohl)
            if (preg_match('/\b(aber|statt|obwohl|trotzdem|während|anstatt|nicht.*sondern)\b/i', $sentence)) $score += 2;
            // Optimale Länge für einen Angle
            if ($len >= 40 && $len <= 150) $score += 1;

            if ($score >= 3) {
                $scored[] = ['text' => $sentence, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] - $a['score']);
        // Deduplizieren: sehr ähnliche Sätze (erste 40 Zeichen) nur einmal
        $seen = [];
        $top = [];
        foreach ($scored as $item) {
            $key = mb_substr($item['text'], 0, 40);
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $top[] = $item;
            }
            if (count($top) >= 5) break;
        }

        foreach ($top as $item) {
            $icp = $this->rulesService->guessIcp($cleanContent, null, $strategy);
            $cluster = $this->rulesService->pickPainCluster($cleanContent, $strategy);
            $statementType = $this->rulesService->pickStatementType(null, 'text', $item['text']);

            // Inline-Zeilenumbrüche + übrige Sonderzeichen-Präfixe aus dem Angle-Text entfernen
            $angleText = trim(preg_replace('/[\r\n\t]+/', ' ', $item['text']));
            $angleText = preg_replace('/\s{2,}/', ' ', $angleText);
            // Überschrift-Präfixe strippen: "HEBEL 03 ...", "Marketing ..." o.ä.
            $angleText = preg_replace('/^HEBEL\s*\d+\s*/i', '', $angleText);
            $angleText = preg_replace('/^(?:[A-ZÄÖÜ0-9&\s\-–—·:]{2,25})\s+(?=[A-ZÄÖÜa-zäöü])/', '', $angleText);
            $angleText = preg_replace('/^[\s→✓•·▸\-–—]+/', '', $angleText);
            $angleText = trim($angleText);

            $angle = Angle::create([
                'angle' => $angleText,
                'strategy_id' => $strategy->id,
                'source_id' => $source->id,
                'batch_key' => $source->batch_key,
                'icp' => $icp,
                'pain_cluster' => $cluster ? "{$cluster['code']} · {$cluster['name']}" : null,
                'statement_type' => $statementType,
            ]);
            $angles[] = $angle;
        }

        // Fallback: wenn nach Bereinigung nichts brauchbares übrig
        if (empty($angles)) {
            // Ersten sinnvollen Satz (>30 Zeichen, keine URLs) als Angle
            $fallback = collect(preg_split('/\n/', $cleanContent))
                ->map(fn ($l) => trim($l))
                ->first(fn ($l) => mb_strlen($l) > 30 && !preg_match('/https?:|www\./i', $l));

            if ($fallback) {
                $angles[] = Angle::create([
                    'angle' => mb_substr($fallback, 0, 200),
                    'strategy_id' => $strategy->id,
                    'source_id' => $source->id,
                    'batch_key' => $source->batch_key,
                ]);
            }
        }

        return $angles;
    }
}