<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Angle;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Persona;
use App\Models\Strategy;
use App\Services\ContentRulesService;
use App\Services\MediaBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(
        private ContentRulesService $rulesService,
        private MediaBriefingService $mediaService,
    ) {}

    /**
     * Empfiehlt einen Statement-Typ inkl. Begründung für eine Format/Funnel-Kombination.
     * Wird vom Produzieren-UI live aufgerufen, sobald der Nutzer den Post-Typ wechselt.
     */
    public function recommendStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|string|in:linkedin_post,ad_copy,newsletter_acquisition,landing_page_headlines,newsletter_bk,blog_post',
            'funnel' => 'nullable|string|in:ToFu,MoFu,BoFu',
            'icp' => 'nullable|string',
            'angle' => 'nullable|string',
        ]);

        $rec = $this->rulesService->recommendStatementType(
            $validated['format'],
            $validated['funnel'] ?? null,
            $validated['icp'] ?? null,
            $validated['angle'] ?? '',
        );

        return response()->json([
            'statement_type' => $rec['type'],
            'reason' => $rec['reason'],
            'hint' => $this->rulesService->statementTypeHint($rec['type']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContentItem::with(['strategy', 'angle', 'media']);

        if ($request->filled('strategy')) {
            $query->whereHas('strategy', fn ($q) => $q->where('key', $request->input('strategy')));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('icp')) {
            $query->where('icp', $request->input('icp'));
        }

        $items = $query->latest()->paginate($request->input('per_page', 50));

        return response()->json($items);
    }

    public function storeIdee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'input' => 'required|string',
            'strategy' => 'required|string|exists:strategies,key',
            'icp' => 'nullable|string',
            'source_type' => 'nullable|string',
            'persona_id' => 'nullable|string|exists:personas,id',
        ]);

        $strategy = Strategy::where('key', $validated['strategy'])->firstOrFail();

        // Auto-detect ICP and cluster
        $icp = $this->rulesService->guessIcp($validated['input'], $validated['icp'] ?? null, $strategy);
        $cluster = $this->rulesService->pickPainCluster($validated['input'], $strategy);
        $sourceType = $this->rulesService->guessSourceType($validated['input'], $validated['source_type'] ?? null);
        $statementType = $this->rulesService->pickStatementType(null, $sourceType, $validated['input']);

        // Match persona — prefer explicit, then topic match, then first active
        // (nur globale Personas, die dieser Strategie zugeordnet sind)
        $persona = null;
        $mappedPersonas = $strategy->personas()->where('active', true)->get();
        if (!empty($validated['persona_id'])) {
            $persona = $mappedPersonas->firstWhere('id', $validated['persona_id']) ?? Persona::find($validated['persona_id']);
        } else {
            // Try to match by topic (aus dem Strategie-Mapping)
            foreach ($mappedPersonas as $p) {
                $mapping = $p->strategyMapping($strategy->id);
                foreach ((array) ($mapping['topic_clusters'] ?? []) as $topic) {
                    if (stripos($validated['input'], $topic) !== false) {
                        $persona = $p;
                        break 2;
                    }
                }
            }
            $persona ??= $mappedPersonas->first();
        }

        $item = ContentItem::create([
            'strategy_id' => $strategy->id,
            'type' => 'idea',
            'content' => $validated['input'],
            'status' => 'idee',
            'icp' => $icp,
            'pain_cluster' => $cluster ? "{$cluster['code']} · {$cluster['name']}" : null,
            'statement_type' => $statementType,
            'persona_id' => $persona?->id,
            'owner' => $persona?->name ?? $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
        ]);

        return response()->json($item->load(['strategy', 'media']), 201);
    }

    public function produzieren(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'angle_id' => 'required|string|exists:angles,id',
            'format' => 'required|string|in:linkedin_post,ad_copy,newsletter_acquisition,landing_page_headlines,newsletter_bk',
            'pattern' => 'nullable|string|in:contrarian_take,data_drop,mistake_post,framework',
            'persona_id' => 'nullable|string|exists:personas,id',
            'metric' => 'nullable|string',
            'mechanism' => 'nullable|string',
            'proofs' => 'nullable|string',
            'kpis' => 'nullable|string',
            'cta' => 'nullable|string',
            'strategy' => 'nullable|string|exists:strategies,key',
            'statement_type' => 'nullable|string|in:Direkt,Drastisch,Bedrohlich,Gain,Mechanismus,Vision,Sarkastisch',
            // NEU: Varianten-Generierung (A/B-fähig)
            'variants_count' => 'sometimes|integer|min:1|max:5',
            'variant_patterns' => 'sometimes|array',
            'variant_patterns.*' => 'string|in:story,listicle,contrarian,question,data_drop',
        ]);

        $angle = Angle::with('strategy')->findOrFail($validated['angle_id']);
        $strategy = $angle->strategy;

        // Statement-Typ: explizit übergeben > Empfehlung aus Format/Funnel/Text.
        $statementType = $validated['statement_type'] ?? null;
        if (! $statementType) {
            $statementType = $this->rulesService->recommendStatementType(
                $validated['format'],
                $angle->funnel,
                $angle->icp,
                $angle->angle,
            )['type'];
        }
        $validated['statement_type'] = $statementType;
        $validated['statement_type_hint'] = $this->rulesService->statementTypeHint($statementType);

        // Get strategy context
        $strategyCtx = [];
        foreach ($strategy->contentStrategies as $s) {
            $strategyCtx[$s->key] = $s->content;
        }

        // Get persona context (if persona is set on angle or matched)
        $personaCtx = null;
        if (!empty($validated['persona_id'])) {
            $persona = Persona::find($validated['persona_id']);
            if ($persona) {
                $mapping = $persona->strategyMapping($strategy->id);
                $personaCtx = [
                    'persona_id' => $persona->id,
                    'name' => $persona->name,
                    'role' => $persona->role,
                    'voice' => $persona->voice,
                    'tonality' => $persona->tonality,
                    'positioning' => $persona->positioning,
                    'core_statements' => $persona->core_statements,
                    'topics' => $mapping['topic_clusters'] ?? [],
                    'content_attributes' => $persona->content_attributes,
                    'perspective' => $persona->perspective,
                    'emoji_usage' => $persona->emoji_usage,
                    'max_sentence_length' => $persona->max_sentence_length,
                    'forbidden_words' => $persona->forbidden_words,
                ];
            }
        } elseif ($angle->contentItems->first()?->persona_id) {
            $persona = Persona::find($angle->contentItems->first()->persona_id);
            if ($persona) {
                $personaCtx = [
                    'name' => $persona->name,
                    'role' => $persona->role,
                    'voice' => $persona->voice,
                    'tonality' => $persona->tonality,
                ];
            }
        }

        // Varianten-Logik: 1 (Default) oder mehrere A/B-Varianten
        $variantCount = $validated['variants_count'] ?? 1;
        $patterns = $validated['variant_patterns']
            ?? ['contrarian', 'listicle', 'story', 'question', 'data_drop'];
        $groupId = $variantCount > 1 ? \Illuminate\Support\Str::uuid()->toString() : null;

        $items = [];
        $violations = [];
        $missingCta = [];

        foreach (array_slice($patterns, 0, $variantCount) as $pattern) {
            $params = $validated;
            $params['pattern'] = $pattern;

            $item = $this->produceSingleContent($angle, $params, $strategy, $strategyCtx, $personaCtx, $groupId, $pattern);
            $items[] = $item;

            // Tone-Verletzungen sammeln (pro Variante)
            $v = $this->rulesService->checkToneViolations($item->content, $strategy, $strategyCtx);
            if ($v) {
                $violations[$item->id] = $v;
            }

            // Pflicht-CTA-Check (pro Variante)
            if (!$this->rulesService->checkMandatoryCta($item->content, $strategy)) {
                $missingCta[$item->id] = true;
            }
        }

        // Backward-kompatible Response: bei 1 Variante zusätzlich content_item setzen
        $response = [
            'items'            => $items,
            'variant_group_id' => $groupId,
            'tone_violations'  => $violations,
            'missing_cta'      => $missingCta,
            'statement_type'   => $statementType,
            'statement_type_reason' => $this->rulesService->recommendStatementType(
                $validated['format'],
                $angle->funnel,
                $angle->icp,
                $angle->angle,
            )['reason'],
        ];
        if (count($items) === 1) {
            $response['content_item'] = $items[0]->load(['strategy', 'angle', 'media']);
        }

        return response()->json($response, 201);
    }

    /**
     * Erzeugt genau ein ContentItem (inkl. LLM-Generierung, Tone-Enforcement
     * und Media-Briefings). Wird von produzieren() pro Variante aufgerufen.
     */
    private function produceSingleContent(Angle $angle, array $params, Strategy $strategy, array $strategyCtx, ?array $personaCtx, ?string $groupId, ?string $pattern): ContentItem
    {
        $content = $this->generateContentWithLLM($angle, $params, $strategy, $strategyCtx, $personaCtx);
        $content = $this->rulesService->enforceTone($content, $params['format'], $strategy, $strategyCtx);

        $item = ContentItem::create([
            'strategy_id' => $strategy->id,
            'angle_id' => $angle->id,
            'type' => 'post',
            'format' => $params['format'],
            'title' => mb_substr($angle->angle, 0, 80),
            'content' => $content,
            'status' => 'in_produktion',
            'icp' => $angle->icp,
            'pain_cluster' => $angle->pain_cluster,
            'statement_type' => $angle->statement_type,
            'owner' => $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
            'variant_group_id' => $groupId,
            'variant_pattern' => $pattern,
        ]);

        // Auto-create media briefings
        $briefings = $this->mediaService->buildBriefings($params['format'], [
            'angle' => $angle->angle,
            'icp' => $angle->icp,
        ], $strategyCtx);

        foreach ($briefings as $briefing) {
            ContentMedia::create([
                'content_item_id' => $item->id,
                'strategy_id' => $strategy->id,
                'type' => $briefing['type'],
                'format' => $briefing['briefing']['generation_params']['aspect_ratio'] ?? null,
                'briefing' => $briefing['briefing'],
                'position' => $briefing['position'],
            ]);
        }

        return $item;
    }

    public function update(Request $request, ContentItem $contentItem): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'status' => 'sometimes|string|in:' . implode(',', ContentItem::STATUSES),
            'format' => 'sometimes|string',
            'owner' => 'sometimes|string',
            'live_date' => 'sometimes|date',
            'icp' => 'sometimes|string',
            'persona_id' => 'sometimes|string',
        ]);

        $contentItem->update($validated);

        return response()->json($contentItem->load(['strategy', 'angle', 'media']));
    }

    /**
     * Assistant-Edit: überarbeitet den Content eines Items anhand einer
     * freitextlichen Anweisung (LLM) und speichert die neue Version.
     */
    public function assistantEdit(Request $request, ContentItem $contentItem): JsonResponse
    {
        $validated = $request->validate([
            'instruction' => 'required|string|max:1000',
            'apply' => 'sometimes|boolean', // true = sofort speichern, false = nur Vorschlag
        ]);

        $edenaiKey = \App\Models\Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        if (! $edenaiKey) {
            return response()->json(['error' => 'EdenAI Key nicht konfiguriert.'], 422);
        }

        $strategy = $contentItem->strategy;

        $prompt = "Überarbeite folgenden Content-Post im Format \"{$contentItem->format}\".\n\n"
            . "AKTUELLER CONTENT:\n```\n{$contentItem->content}\n```\n\n"
            . "ANWEISUNG DES NUTZERS:\n{$validated['instruction']}\n\n"
            . "Behalte Format, Länge und Stil bei. Gib NUR den fertigen überarbeiteten Text aus "
            . "(keine Erklärungen, keine Meta-Kommentare).";

        $start = microtime(true);
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $edenaiKey,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.edenai.run/v3/chat/completions', [
                'model' => 'openai/gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein B2B-Content-Redakteur.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.6,
                'max_tokens' => 1500,
            ]);

            $text = $response->json('choices.0.message.content');

            \App\Models\AgentLog::create([
                'agent'       => 'assistant',
                'provider'    => 'edenai/openai',
                'model'       => 'openai/gpt-4o',
                'input'       => substr($validated['instruction'], 0, 2000),
                'output'      => substr($text ?? '', 0, 2000),
                'status'      => $text ? 'success' : 'error',
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            if (! $text || strlen(trim($text)) < 20) {
                return response()->json(['error' => 'Keine brauchbare Antwort vom Modell.'], 502);
            }

            $text = trim($text);

            $applied = false;
            if ($validated['apply'] ?? true) {
                $contentItem->update(['content' => $text, 'status' => 'review']);
                $applied = true;
            }

            return response()->json([
                'content_item' => $contentItem->fresh()->load(['strategy', 'angle', 'media']),
                'suggested' => $text,
                'applied' => $applied,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Wählt eine A/B-Variante: setzt sie auf 'geplant' und verwirft alle
     * anderen Items derselben variant_group_id.
     */
    public function selectVariant(Request $request, ContentItem $contentItem): JsonResponse
    {
        if (!$contentItem->variant_group_id) {
            return response()->json(['message' => 'Item gehört zu keiner Varianten-Gruppe.'], 422);
        }

        $groupId = $contentItem->variant_group_id;

        \Illuminate\Support\Facades\DB::transaction(function () use ($contentItem, $groupId) {
            // Alle anderen der Gruppe verworfen
            ContentItem::where('variant_group_id', $groupId)
                ->where('id', '!=', $contentItem->id)
                ->update(['status' => 'verworfen']);

            // Gewählte Variante geplant
            $contentItem->update(['status' => 'geplant']);
        });

        $siblings = ContentItem::where('variant_group_id', $groupId)->get();

        return response()->json([
            'selected' => $contentItem->load(['strategy', 'angle', 'media']),
            'group'    => $siblings,
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $strategyKey = $request->input('strategy', 'viscale');
        $strategy = Strategy::where('key', $strategyKey)->firstOrFail();
        $topAngles = Angle::where('strategy_id', $strategy->id)
            ->whereNotNull('ranking_score')
            ->orderByDesc('ranking_score')
            ->limit(5)
            ->get();

        $totalItems = ContentItem::where('strategy_id', $strategy->id)->count();
        $byStatus = ContentItem::where('strategy_id', $strategy->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $totalMedia = ContentMedia::where('strategy_id', $strategy->id)->count();
        $mediaByStatus = ContentMedia::where('strategy_id', $strategy->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $upcomingPlan = \App\Models\RedaktionsplanEntry::where('strategy_id', $strategy->id)
            ->where('planned_date', '>=', now()->toDateString())
            ->orderBy('planned_date')
            ->limit(10)
            ->with('contentItem')
            ->get();

        return response()->json([
            'strategy' => $strategy->only(['key', 'name']),
            'stats' => [
                'total_angles' => $totalAngles,
                'total_content_items' => $totalItems,
                'total_media' => $totalMedia,
                'by_status' => $byStatus,
                'media_by_status' => $mediaByStatus,
            ],
            'top_angles' => $topAngles,
            'upcoming_plan' => $upcomingPlan,
        ]);
    }

    /**
     * Generiert Content via LLM anhand von Pattern-Template, Kanal-Regeln,
     * Brand Voice und Persona. Fällt bei fehlendem Key/Fehler auf den
     * deterministischen Builder zurück.
     */
    private function generateContentWithLLM(Angle $angle, array $params, Strategy $strategy, array $strategyCtx, ?array $personaCtx): string
    {
        $edenaiKey = \App\Models\Setting::where('key', 'llm_keys')->first()?->value['edenai_key'] ?? null;
        $format = $params['format'];
        $pattern = $params['pattern'] ?? null;

        if (!$edenaiKey) {
            return $this->generateContent($angle, $params, $strategyCtx, $personaCtx);
        }

        $channelRules = $strategyCtx['channel_rules'][$format] ?? [];
        $brandVoice = $strategyCtx['brand_voice'] ?? [];
        $template = $pattern ? ($strategyCtx['post_templates'][$pattern] ?? null) : null;

        $prompt = $this->buildContentPrompt($angle, $params, $format, $template, $channelRules, $brandVoice, $personaCtx, $strategy);

        $start = microtime(true);
        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $edenaiKey,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.edenai.run/v3/chat/completions', [
                'model' => 'openai/gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'Du bist ein B2B-Content-Texter. Schreibe NUR den fertigen Content-Text, keine Erklärungen, keine Meta-Kommentare.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1200,
            ]);

            $text = $response->json('choices.0.message.content');

            // Kosten-Tracking: jeder LLM-Call wird geloggt (auch Fehlschläge)
            \App\Models\AgentLog::create([
                'agent'       => 'production',
                'provider'    => 'edenai/openai',
                'model'       => 'openai/gpt-4o',
                'input'       => substr($prompt, 0, 2000),
                'output'      => substr($text ?? '', 0, 2000),
                'status'      => $text ? 'success' : 'error',
                'tokens_used' => $response->json('usage.total_tokens'),
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            if ($text && strlen(trim($text)) > 20) {
                return trim($text);
            }
        } catch (\Throwable $e) {
            \App\Models\AgentLog::create([
                'agent'       => 'production',
                'provider'    => 'edenai/openai',
                'model'       => 'openai/gpt-4o',
                'input'       => substr($prompt, 0, 2000),
                'output'      => substr('Exception: ' . $e->getMessage(), 0, 2000),
                'status'      => 'error',
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            // Fallback unten
        }

        return $this->generateContent($angle, $params, $strategyCtx, $personaCtx);
    }

    /**
     * Kurzer Stil-Hinweis pro A/B-Variante, damit die Varianten unterschiedlich ansetzen.
     */
    private function variantPatternHint(string $pattern): ?string
    {
        return match ($pattern) {
            'story'      => 'Erzähle es als kurze Anekdoten-/Fallgeschichten-Erzählung (Storytelling).',
            'listicle'   => 'Strukturiere es als kompakte Liste (3-5 konkrete Punkte).',
            'contrarian' => 'Nimm einen konträren, provokativen Standpunkt, der den Mainstream widerspricht.',
            'question'   => 'Führe mit einer zentralen, spannungsreich gestellten Frage ein.',
            'data_drop'  => 'Beginne mit einer konkreten Zahl/Metrik als Hook und baue darauf auf.',
            default      => null,
        };
    }

    /**
     * Baut den Prompt für die Content-Generierung aus Regelwerk + Kontext.
     */
    private function buildContentPrompt(Angle $angle, array $params, string $format, ?array $template, array $channelRules, array $brandVoice, ?array $personaCtx, Strategy $strategy): string
    {
        $lines = [];
        $lines[] = "Schreibe einen {$format} für folgenden Content-Angle.";
        $lines[] = "";

        // ═══ LAYER 1: CONTENT-KERN (Was soll gesagt werden?) ═══
        $lines[] = "## CONTENT-KERN";
        $lines[] = "ANGLE (Kernaussage): {$angle->angle}";
        if ($angle->icp) {
            $lines[] = "ICP: {$angle->icp}";
        }
        if ($angle->pain_cluster) {
            $lines[] = "Pain-Cluster: {$angle->pain_cluster}";
        }
        foreach (['metric', 'mechanism', 'proofs', 'cta'] as $k) {
            if (!empty($params[$k])) {
                $lines[] = strtoupper($k) . " (vorgegeben): {$params[$k]}";
            }
        }
        if (!empty($params['statement_type_hint'])) {
            $lines[] = "STATEMENT-TYP ({$params['statement_type']}): {$params['statement_type_hint']}";
        }
        if ($template) {
            $lines[] = "PATTERN: {$template['label']} — {$template['beschreibung']}";
            $lines[] = "Struktur: " . implode(' → ', $template['struktur']);
            $lines[] = "Beispiel-Hook: \"{$template['beispiel_hook']}\"";
        } elseif (!empty($params['variant_pattern'])) {
            // A/B-Variante: Stil-Hinweis, damit jede Variante anders ansetzt
            $hint = $this->variantPatternHint($params['variant_pattern']);
            if ($hint) {
                $lines[] = "VARIANTE ({$params['variant_pattern']}): {$hint}";
            }
        }

        // ═══ LAYER 2: STIL-LAYER (Wie soll es klingen?) ═══
        $lines[] = "";
        $lines[] = "## STIL-LAYER (Persona)";
        if ($personaCtx) {
            $lines[] = "Persona: {$personaCtx['name']} ({$personaCtx['role']})";
            if (!empty($personaCtx['voice'])) {
                $lines[] = "Sprachstil: {$personaCtx['voice']}";
            }
            if (!empty($personaCtx['perspective'])) {
                $lines[] = "Perspektive: {$personaCtx['perspective']}";
            }
            if (!empty($personaCtx['emoji_usage']) && $personaCtx['emoji_usage'] !== 'none') {
                $lines[] = "Emoji-Nutzung: {$personaCtx['emoji_usage']}";
            } elseif (!empty($personaCtx['emoji_usage']) && $personaCtx['emoji_usage'] === 'none') {
                $lines[] = "Emoji-Nutzung: keine Emojis verwenden";
            }
            if (!empty($personaCtx['max_sentence_length'])) {
                $lines[] = "Max. Satzlänge: {$personaCtx['max_sentence_length']} Wörter";
            }
            if (!empty($personaCtx['forbidden_words'])) {
                $lines[] = "VERBOTENE WÖRTER (Persona): " . implode(', ', (array) $personaCtx['forbidden_words']);
            }
            if (!empty($personaCtx['core_statements'])) {
                $lines[] = "Überzeugungen: " . implode(' | ', array_slice((array) $personaCtx['core_statements'], 0, 3));
            }

            // Few-Shot: kuratierte Referenz-Beispiele der Persona (gleiches Format)
            if (!empty($personaCtx['persona_id'])) {
                $examples = \App\Models\PersonaExample::where('persona_id', $personaCtx['persona_id'])
                    ->where('format', $format)
                    ->where('active', true)
                    ->limit(2)
                    ->get();

                if ($examples->count() > 0) {
                    $lines[] = "";
                    $lines[] = "### Referenz-Beispiele (Few-Shot)";
                    foreach ($examples as $i => $ex) {
                        $lines[] = "Beispiel " . ($i + 1) . ":";
                        $lines[] = "```";
                        $lines[] = $ex->content;
                        $lines[] = "```";
                        if ($ex->why_good) {
                            $lines[] = "Warum gut: {$ex->why_good}";
                        }
                    }
                }
            }
        }
        $voiceRules = $brandVoice['rules'] ?? [];
        if ($voiceRules) {
            $lines[] = "BRAND VOICE:\n- " . implode("\n- ", $voiceRules);
        }

        // ═══ LAYER 3: ZIEL-LAYER (Kanal, Format, CTA) ═══
        $lines[] = "";
        $lines[] = "## ZIEL-LAYER (Kanal/Format)";
        $rulesText = [];
        if (!empty($channelRules['word_count'])) {
            $rulesText[] = "Länge: {$channelRules['word_count']['min']}-{$channelRules['word_count']['max']} Wörter";
        }
        if (!empty($channelRules['hook_max_words'])) {
            $rulesText[] = "Hook (Zeile 1): max {$channelRules['hook_max_words']} Wörter, provokante These";
        }
        if (!empty($channelRules['structure'])) {
            $rulesText[] = "Aufbau: " . implode(' → ', $channelRules['structure']);
        }
        if (!empty($channelRules['cta_style'])) {
            $rulesText[] = "CTA: {$channelRules['cta_style']}";
        }
        if (!empty($channelRules['primary_text_max_chars'])) {
            $rulesText[] = "Primary Text: max {$channelRules['primary_text_max_chars']} Zeichen";
        }
        if (!empty($channelRules['headline_max_chars'])) {
            $rulesText[] = "Headline: max {$channelRules['headline_max_chars']} Zeichen";
        }
        if ($rulesText) {
            $lines[] = "KANAL-REGELN:\n- " . implode("\n- ", $rulesText);
        }

        $hashtags = $strategy->hashtags ?? [];
        if ($format === 'linkedin_post' && $hashtags) {
            $lines[] = "Hashtags am Ende: " . implode(' ', array_slice($hashtags, 0, 5));
        }

        $lines[] = "";
        $lines[] = "Gib NUR den Content-Text aus.";

        return implode("\n", $lines);
    }

    private function generateContent(Angle $angle, array $params, array $strategyCtx, ?array $personaCtx = null): string
    {
        // Build context-aware content with persona + brand voice
        $parts = [];
        $parts[] = $angle->angle;
        $parts[] = '';

        // Persona-specific intro
        if ($personaCtx) {
            $parts[] = "— {$personaCtx['name']} ({$personaCtx['role']})";
            $parts[] = '';
        }

        if (!empty($params['metric'])) {
            $parts[] = $params['metric'];
        }
        if (!empty($params['mechanism'])) {
            $parts[] = $params['mechanism'];
        }
        if (!empty($params['proofs'])) {
            $parts[] = $params['proofs'];
        }
        if (!empty($params['cta'])) {
            $parts[] = '';
            $parts[] = $params['cta'];
        }

        // Add hashtags
        $strategy = $angle->strategy;
        $hashtags = $strategy->hashtags ?? [];
        if ($hashtags) {
            $parts[] = '';
            $parts[] = implode(' ', $hashtags);
        }

        return implode("\n", $parts);
    }

    /**
     * Preview content in different formats (LinkedIn, Ad, Newsletter).
     */
    public function preview(Request $request, ContentItem $contentItem): JsonResponse
    {
        $contentItem->load(['strategy', 'angle', 'media']);
        $persona = $contentItem->persona_id ? Persona::find($contentItem->persona_id) : null;

        return response()->json([
            'item' => $contentItem,
            'persona' => $persona,
            'preview' => [
                'linkedin' => $this->renderLinkedInPreview($contentItem, $persona),
                'ad' => $this->renderAdPreview($contentItem),
                'newsletter' => $this->renderNewsletterPreview($contentItem),
            ],
        ]);
    }

    private function renderLinkedInPreview(ContentItem $item, ?Persona $persona): array
    {
        return [
            'platform' => 'LinkedIn',
            'author_name' => $persona?->name ?? $item->strategy->name,
            'author_role' => $persona?->role ?? 'Content Team',
            'author_avatar' => substr($persona?->name ?? $item->strategy->name, 0, 1),
            'time' => now()->diffForHumans(),
            'text' => $item->content,
            'hashtags' => $item->strategy->hashtags ?? [],
            'media' => $item->media->map(fn ($m) => ['type' => $m->type, 'format' => $m->format])->toArray(),
            'engagement' => ['likes' => 0, 'comments' => 0, 'shares' => 0],
        ];
    }

    private function renderAdPreview(ContentItem $item): array
    {
        $lines = explode("\n", $item->content);
        return [
            'platform' => 'Ad',
            'primary_text' => $lines[0] ?? '',
            'headline' => $lines[1] ?? '',
            'description' => $lines[2] ?? '',
            'cta' => $item->angle?->cta ?? 'Mehr erfahren',
            'image_url' => $item->media->first()?->url ?? null,
        ];
    }

    private function renderNewsletterPreview(ContentItem $item): array
    {
        $lines = explode("\n", $item->content);
        return [
            'platform' => 'Newsletter',
            'subject' => $lines[0] ?? $item->title,
            'preview_text' => $lines[1] ?? '',
            'body' => implode("\n\n", array_slice($lines, 2)),
        ];
    }
}
