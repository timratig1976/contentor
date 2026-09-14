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
        private \App\Services\LlmService $llm,
        private \App\Services\KpiLearningService $kpiLearning,
        private \App\Services\ContentQualityService $quality,
    ) {}

    /**
     * Empfiehlt einen Statement-Typ inkl. Begründung für eine Format/Funnel-Kombination.
     * Wird vom Produzieren-UI live aufgerufen, sobald der Nutzer den Post-Typ wechselt.
     */
    public function recommendStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|string|in:linkedin_post,ad_copy,newsletter,landing_page_headlines,blog_post',
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
            'persona_id' => 'nullable|integer|exists:personas,id',
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
            'format' => 'required|string|in:linkedin_post,ad_copy,newsletter,landing_page_headlines,blog_post',
            'pattern' => 'nullable|string|in:contrarian_take,data_drop,mistake_post,framework',
            'persona_id' => 'nullable|integer|exists:personas,id',
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
            'variant_patterns.*' => 'string|in:story,listicle,contrarian,question,data_drop,mistake_post,framework',
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
                    'perspective' => $persona->perspective,
                    'emoji_usage' => $persona->emoji_usage,
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

        // Kein Persona-Kontext → Default-Persona der Strategie nutzen (is_default im Mapping)
        if (! $personaCtx) {
            $defaultPersona = $strategy->personas()
                ->where('active', true)
                ->wherePivot('is_default', true)
                ->first();
            if ($defaultPersona) {
                $mapping = $defaultPersona->strategyMapping($strategy->id);
                $personaCtx = [
                    'persona_id' => $defaultPersona->id,
                    'name' => $defaultPersona->name,
                    'role' => $defaultPersona->role,
                    'voice' => $defaultPersona->voice,
                    'tonality' => $defaultPersona->tonality,
                    'positioning' => $defaultPersona->positioning,
                    'core_statements' => $defaultPersona->core_statements,
                    'topics' => $mapping['topic_clusters'] ?? [],
                    'perspective' => $defaultPersona->perspective,
                    'emoji_usage' => $defaultPersona->emoji_usage,
                    'forbidden_words' => $defaultPersona->forbidden_words,
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

            // Befunde aus dem Quality-Gate (nach Auto-Fix!) für die Response sammeln
            $flags = $item->quality_flags ?? [];
            if (!empty($flags['tone_violations'])) {
                $violations[$item->id] = $flags['tone_violations'];
            }
            if (!empty($flags['missing_cta'])) {
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
            'title' => $this->deriveTitle($content, $params['format'], $angle),
            'content' => $content,
            'status' => 'in_produktion',
            'icp' => $angle->icp,
            'pain_cluster' => $angle->pain_cluster,
            'statement_type' => $angle->statement_type,
            'owner' => $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
            'variant_group_id' => $groupId,
            'variant_pattern' => $pattern,
        ]);

        // Quality-Gate: Regel-Check → Auto-Fix (max 2 Runden) → LLM-Score → Persistenz
        $item = $this->quality->gate($item, $strategy, $strategyCtx);

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

        // Hashtag-Spam bereinigen (defensiv bei jeder Content-Änderung)
        if (!empty($validated['content'])) {
            $validated['content'] = $this->rulesService->sanitizeHashtags($validated['content']);
        }

        $contentItem->update($validated);

        return response()->json($contentItem->load(['strategy', 'angle', 'media']));
    }

    /**
     * Löscht einen Content-Item dauerhaft (inkl. zugehöriger Medien und KPIs).
     * Wird vom Editor-„Verwerfen" genutzt, damit der verworfenen Post auch
     * aus dem Output/Redaktionsplan verschwindet (statt nur den Status zu setzen).
     */
    public function destroy(ContentItem $contentItem): JsonResponse
    {
        $contentItem->delete();

        return response()->json(['deleted' => $contentItem->id]);
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

        $prompt = "Überarbeite folgenden Content-Post im Format \"{$contentItem->format}\".\n\n"
            . "AKTUELLER CONTENT:\n```\n{$contentItem->content}\n```\n\n"
            . "ANWEISUNG DES NUTZERS:\n{$validated['instruction']}\n\n"
            . "Behalte Format, Länge und Stil bei. Gib NUR den fertigen überarbeiteten Text aus "
            . "(keine Erklärungen, keine Meta-Kommentare).";

        $result = $this->llm->chat('assistant', 'Du bist ein B2B-Content-Redakteur. '
            . 'Schreibe sachlich und ohne Floskeln. '
            . 'Falls der Text Hashtags enthält, nutze maximal 5 kurze, relevante Hashtags am Ende — niemals lange Hashtag-Ketten oder doppelte Hashtags.', [
            ['role' => 'user', 'content' => $prompt],
        ], ['timeout' => 90]);

        if ($result['status'] !== 'success' || ! $result['text'] || strlen(trim($result['text'])) < 20) {
            return response()->json(['error' => $result['error'] ?? 'Keine brauchbare Antwort vom Modell.'], 502);
        }

        $text = trim($result['text']);

        // Guardrail: Tonalität + Hashtag-Spam bereinigen (wie im Produktionspfad)
        $strategyCtx = [];
        foreach ($contentItem->strategy?->contentStrategies ?? [] as $s) {
            $strategyCtx[$s->key] = $s->content;
        }
        if ($contentItem->strategy) {
            $text = $this->rulesService->enforceTone($text, $contentItem->format, $contentItem->strategy, $strategyCtx);
        }

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
        $format = $params['format'];
        $pattern = $params['pattern'] ?? null;

        // Konfiguration des Production-Agents (Agents-Seite → Modell/Prompt/Effort)
        $agentPrompts = \App\Models\Setting::where('key', 'agent_prompts')->first()?->value ?? [];

        $systemPrompt = $agentPrompts['production']
            ?? 'Du bist ein B2B-Content-Texter. Schreibe NUR den fertigen Content-Text, keine Erklärungen, keine Meta-Kommentare.';
        // Strategie-Kontext: Platzhalter ersetzen ODER anhängen (wie im Agent-Test-Pfad),
        // damit ICPs, Cluster, Markt-Lücken und Tonalität immer im System-Prompt landen.
        $contextBlock = app(\App\Services\AgentContextService::class)->build($strategy->key);
        $systemPrompt = str_contains($systemPrompt, '{{strategy_context}}')
            ? str_replace('{{strategy_context}}', $contextBlock, $systemPrompt)
            : $systemPrompt . "\n\n" . $contextBlock;

        // KPI-Lernschleife: Erkenntnisse aus echten Performance-Daten injizieren
        $learnings = $this->kpiLearning->promptBlock($strategy);
        if ($learnings !== '') {
            $systemPrompt .= "\n\n" . $learnings;
        }
        // Der konfigurierbare Prompt richtet sich teils an Tool-Agenten — für den
        // direkten Completion-Call explizit auf Textproduktion umschalten.
        $systemPrompt .= "\n\n---\nAKTUELLER AUFTRAG: Du erhältst gleich einen konkreten Produktionsauftrag. "
            . "Führe KEINE Tool-Aufrufe aus und beschreibe keine Vorgehensweise — schreibe direkt den fertigen Content-Text, sonst nichts.";

        // Kanal-Regeln aus zentraler Quelle (ContentRulesService), DB-Overrides der Strategie mergen automatisch
        $channelRules = $this->rulesService->channelRules($format, $strategyCtx['channel_rules'] ?? []);
        $brandVoice = $strategyCtx['brand_voice'] ?? [];
        $template = $pattern ? $this->findTemplate($strategy, $strategyCtx['post_templates'] ?? [], $pattern) : null;

        $prompt = $this->buildContentPrompt($angle, $params, $format, $template, $channelRules, $brandVoice, $personaCtx, $strategy);

        // Zentraler LLM-Call über LlmService (inkl. Reasoning-Effort & Logging)
        $result = $this->llm->chat('production', $systemPrompt, [
            ['role' => 'user', 'content' => $prompt],
        ], ['timeout' => 90]);

        if ($result['status'] === 'success' && $result['text'] && strlen(trim($result['text'])) > 20) {
            return trim($result['text']);
        }

        // Fallback: deterministischer Builder
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
     * Findet das passende Post-Template zum Varianten-Pattern.
     * Quelle ist der globale PostTemplate-Katalog, gefiltert auf die für
     * diese Strategie ausgewählten IDs (ContentStrategy.key='post_templates'
     * → content['selected']). Fallback auf das Legacy-Array-Schema, falls
     * eine Strategie noch keine Katalog-Auswahl hat.
     */
    private function findTemplate(Strategy $strategy, array $postTemplatesCtx, string $pattern): ?array
    {
        $needles = match ($pattern) {
            'contrarian'  => ['contrarian'],
            'data_drop'   => ['data'],
            'mistake_post' => ['mistake', 'fehler'],
            'framework'   => ['framework', 'modell'],
            'story'       => ['story', 'storytelling'],
            'listicle'    => ['listicle', 'list'],
            'question'    => ['question', 'frage'],
            default       => [$pattern],
        };

        $selectedIds = $postTemplatesCtx['selected'] ?? null;
        if (is_array($selectedIds)) {
            $catalog = \App\Models\PostTemplate::whereIn('id', $selectedIds)->where('active', true)->get();
            foreach ($catalog as $tpl) {
                $name = strtolower($tpl->name);
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        return $tpl->toArray();
                    }
                }
            }

            return null;
        }

        // Legacy-Fallback: altes Array-Schema direkt in ContentStrategy
        $list = $postTemplatesCtx['templates'] ?? null;
        if (is_array($list)) {
            foreach ($list as $tpl) {
                $name = strtolower((string) ($tpl['name'] ?? ''));
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        return $tpl;
                    }
                }
            }

            return null;
        }

        return $postTemplatesCtx[$pattern] ?? null;
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
        foreach (['metric', 'mechanism', 'proofs', 'cta', 'kpis'] as $k) {
            if (!empty($params[$k])) {
                $lines[] = strtoupper($k) . " (vorgegeben): {$params[$k]}";
            }
        }
        if (!empty($params['statement_type_hint'])) {
            $lines[] = "STATEMENT-TYP ({$params['statement_type']}): {$params['statement_type_hint']}";
        }
        if ($template) {
            // Neues Format: name/description/structure/example — Legacy: label/beschreibung/struktur/beispiel_hook
            $tplName = $template['name'] ?? $template['label'] ?? $pattern;
            $tplDesc = $template['description'] ?? $template['beschreibung'] ?? '';
            $tplStructure = $template['structure'] ?? $template['struktur'] ?? null;
            $tplExample = $template['example'] ?? $template['beispiel_hook'] ?? null;

            $lines[] = "PATTERN: {$tplName}" . ($tplDesc ? " — {$tplDesc}" : '');
            if ($tplStructure) {
                $structure = is_array($tplStructure) ? implode(' → ', $tplStructure) : str_replace("\n", ' → ', $tplStructure);
                $lines[] = "Struktur (zwingend einhalten, jeder Punkt = eigener Absatz): " . $structure;
                // Kontext-Absatz erzwingen, falls die Template-Struktur direkt mit der Liste startet
                if (!str_contains(mb_strtolower($structure), 'kontext')) {
                    $lines[] = "Struktur-Hinweis: Hook → Kontext/Problem (eigener Absatz, 2-3 Sätze, BEVOR der erste Listenpunkt beginnt) → " . $structure;
                }
            }
            if ($tplExample) {
                $lines[] = "Beispiel (nur als Stil-Referenz, NICHT kopieren): \"{$tplExample}\"";
            }
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
            // Tonalitäts-Details (stil, perspektive, satzrhythmus, konkretion, verbote)
            $ton = $personaCtx['tonality'] ?? [];
            if (!empty($ton['stil'])) {
                $lines[] = "Tonalität: {$ton['stil']}";
            }
            if (!empty($ton['perspektive'])) {
                $lines[] = "Perspektive: {$ton['perspektive']}";
            }
            if (!empty($ton['satzrhythmus'])) {
                $lines[] = "Satzrhythmus: {$ton['satzrhythmus']}";
            }
            if (!empty($ton['konkretion'])) {
                $lines[] = "Konkretion: {$ton['konkretion']}";
            }
            if (!empty($ton['verbote'])) {
                $lines[] = "VERBOTEN (Tonalität): " . implode(', ', (array) $ton['verbote']);
            }
            if (!empty($personaCtx['positioning'])) {
                $lines[] = "Positionierung: {$personaCtx['positioning']}";
            }
            if (!empty($personaCtx['perspective'])) {
                $lines[] = "Erzählperspektive: {$personaCtx['perspective']}";
            }
            if (!empty($personaCtx['emoji_usage']) && $personaCtx['emoji_usage'] !== 'none') {
                $lines[] = "Emoji-Nutzung: {$personaCtx['emoji_usage']}";
            } elseif (!empty($personaCtx['emoji_usage']) && $personaCtx['emoji_usage'] === 'none') {
                $lines[] = "Emoji-Nutzung: keine Emojis verwenden";
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
        // Brand Voice: unterstützt legacy {rules:[...]} + neues Schema
        // (personality, tone_content/ads/sales, perspective, language_level, jargon, must, never, examples)
        $voiceRules = $brandVoice['rules'] ?? [];
        if ($voiceRules) {
            $lines[] = "BRAND VOICE:\n- " . implode("\n- ", $voiceRules);
        }
        $voiceLines = [];
        if (!empty($brandVoice['personality'])) {
            $voiceLines[] = "Persönlichkeit: {$brandVoice['personality']}";
        }
        // Kontextspezifischer Ton: je nach Format den passenden Ton wählen
        $contextTone = match ($format) {
            'ad_copy'                  => $brandVoice['tone_ads'] ?? null,
            'landing_page_headlines'   => $brandVoice['tone_sales'] ?? $brandVoice['tone_ads'] ?? null,
            'linkedin_post', 'blog_post', 'newsletter' => $brandVoice['tone_content'] ?? null,
            default                    => null,
        };
        $contextTone ??= $brandVoice['tone'] ?? null; // Legacy-Fallback
        if ($contextTone) {
            $voiceLines[] = "Tonalität für dieses Format: {$contextTone}";
        }
        if (!empty($brandVoice['perspective'])) {
            $perspLabels = [
                'ich' => 'Ich-Form (Gründer/Autor)', 'du_singular' => 'Du-Form (singular)',
                'du_plural' => 'Ihr-Form (plural)', 'wir' => 'Wir-Form (Unternehmen)',
            ];
            $voiceLines[] = "Ansprache: " . ($perspLabels[$brandVoice['perspective']] ?? $brandVoice['perspective']);
        }
        if (!empty($brandVoice['language_level'])) {
            $voiceLines[] = "Sprachlevel: {$brandVoice['language_level']}";
        }
        if (isset($brandVoice['jargon']) && !$brandVoice['jargon']) {
            $voiceLines[] = "Kein Branchenjargon — für alle verständlich formulieren";
        }
        if (!empty($brandVoice['must'])) {
            $voiceLines[] = "IMMER tun: " . implode('; ', (array) $brandVoice['must']);
        }
        if (!empty($brandVoice['never'])) {
            $voiceLines[] = "NIEMALS tun: " . implode('; ', (array) $brandVoice['never']);
        }
        if ($voiceLines) {
            $lines[] = "BRAND VOICE (Strategie):\n- " . implode("\n- ", $voiceLines);
        }
        // Vorher/Nachher-Beispiele als Few-Shot in den Prompt
        $bvExamples = array_values(array_filter((array) ($brandVoice['examples'] ?? []), fn ($ex) => !empty($ex['good'])));
        if ($bvExamples) {
            $lines[] = "";
            $lines[] = "BRAND VOICE BEISPIELE (orientiere dich am Stil, nicht am Inhalt):";
            foreach (array_slice($bvExamples, 0, 2) as $i => $ex) {
                if (!empty($ex['context'])) {
                    $lines[] = "Kontext: {$ex['context']}";
                }
                if (!empty($ex['bad'])) {
                    $lines[] = "Nicht so: \"{$ex['bad']}\"";
                }
                $lines[] = "So: \"{$ex['good']}\"";
            }
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
        if (!empty($channelRules['visible_hook_max_chars'])) {
            $rulesText[] = "Sichtbarer Bereich: Die ersten ~{$channelRules['visible_hook_max_chars']} Zeichen sind vor der ‚…mehr anzeigen'-Falte sichtbar — Hook und Kernaussage MÜSSEN komplett in diesem Fenster stehen (Zeile 1-2).";
        }
        if (!empty($channelRules['subject_max_chars'])) {
            $rulesText[] = "Betreff: max {$channelRules['subject_max_chars']} Zeichen";
        }
        if (!empty($channelRules['title_max_chars'])) {
            $rulesText[] = "Titel: max {$channelRules['title_max_chars']} Zeichen";
        }
        if (!empty($channelRules['structure'])) {
            $rulesText[] = "Aufbau (Orientierung, keine starre Checkliste — variiere Reihenfolge/Gewichtung): " . implode(' → ', $channelRules['structure']);
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

        if ($format === 'blog_post') {
            $lines[] = "";
            $lines[] = "Gib in der ERSTEN Zeile den Blog-Titel aus (einprägsam, max 60 Zeichen, OHNE # oder andere Markdown-Zeichen), "
                . "danach eine Leerzeile und anschließend den Fließtext. "
                . "Der Titel darf NICHT identisch mit der Angle-Formulierung sein.";
        }

        $lines[] = "";
        $lines[] = "Formatierung: KEIN Markdown (keine **, keine #-Überschriften). Nur Zeilenumbrüche.";
        $lines[] = $this->paragraphFormattingRule($format);
        $lines[] = "";
        $lines[] = $this->humanWritingConstraints();
        $lines[] = "";
        $lines[] = "Gib NUR den Content-Text aus.";

        return implode("\n", $lines);
    }

    /**
     * Absatz-Regel: erzwingt sichtbare Absatztrennung (Leerzeile zwischen
     * Sinnabschnitten), damit LinkedIn/Newsletter-Posts nicht als Fließtext-
     * Wand wirken — unabhängig vom inhaltlichen Aufbau (Templates bleiben
     * inhaltlich unverändert, nur die visuelle Gliederung wird erzwungen).
     */
    private function paragraphFormattingRule(string $format): string
    {
        if ($format === 'blog_post') {
            return "Absätze: Jeder Gedankenschritt bekommt einen eigenen Absatz (Leerzeile dazwischen). "
                . "Absätze sind 2-4 Sätze lang — keine Textwand, aber auch nicht jeder Satz einzeln.";
        }

        return "Absätze: NIEMALS alles als einen einzigen Fließtext-Block schreiben. "
            . "Trenne Hook, Kontext, Mechanismus/Beleg, Konsequenz und CTA jeweils durch eine Leerzeile in eigene Absätze (2-3 Sätze je Absatz, der Hook darf 1 Zeile bleiben). "
            . "So bleibt die inhaltliche Struktur erkennbar, aber der Text wirkt nicht wie eine Wand aus Text.";
    }

    /**
     * Anti-KI-Sound-Block: erzwingt Variation und verbietet die typischsten
     * LLM-Rhetorik-Tics, die generierten Text sofort als "von einer KI
     * geschrieben" verraten. Eine Instruktion wird pro Aufruf zufällig
     * gewählt, damit nicht jeder Post exakt dieselbe Stilanweisung bekommt.
     */
    private function humanWritingConstraints(): string
    {
        $lines = [];
        $lines[] = "## MENSCHLICHKEIT (zwingend)";
        $lines[] = "VERBOTEN — diese KI-Textmuster NIEMALS verwenden:";
        $lines[] = "- Die Antithese-Formel \"Das klingt nach X. Es ist Y.\" oder \"Das wirkt wie X. In Wahrheit ist es Y.\" (und jede Variante davon)";
        $lines[] = "- Perfekt parallele 3er-Aufzählungen mit Doppelpunkt (\"kein A, kein B, kein C\" / \"weder X noch Y noch Z\")";
        $lines[] = "- Ein isolierter Merksatz/Aphorismus als eigene Zeile am Absatzende (\"Das Wissen sitzt im Kopf.\"-Stil)";
        $lines[] = "- Rhetorische Frage-Antwort-Muster (\"Warum ist das so? Weil...\")";
        $lines[] = "- Übergänge wie \"Das Ergebnis:\", \"Die Konsequenz:\", \"Fazit:\" als Einzeiler vor einer Aussage";
        $lines[] = $this->randomHumanTic();

        return implode("\n", $lines);
    }

    /**
     * Eine von mehreren gleichwertigen "Unperfektheits"-Anweisungen, zufällig
     * pro Generierung gewählt — sorgt für Varianz zwischen Posts, die sonst
     * alle nach demselben Muster klingen würden.
     */
    private function randomHumanTic(): string
    {
        $tics = [
            'Lass einen Gedanken bewusst unfertig oder hänge einen Nebengedanken mit "—" an, wie beim Sprechen.',
            'Baue einen leicht unrunden Satz ein (zu lang oder mit Gedankensprung) statt durchgängig glatter Syntax.',
            'Verzichte auf eine klare Drei-Akt-Struktur — steig mitten in einen Gedanken ein, ohne ihn erst anzukündigen.',
            'Nutze eine leicht umgangssprachliche Wendung oder einen Halbsatz, der nicht perfekt grammatisch ist.',
            'Wiederhole ein Wort bewusst statt ein elegantes Synonym zu suchen — wie ein Mensch es beim Schreiben tun würde.',
        ];

        return 'ZUSÄTZLICH: ' . $tics[array_rand($tics)];
    }

    /**
     * Leitet den Anzeige-Titel eines ContentItems aus dem generierten Text ab.
     *
     * - blog_post:  erster Absatz (H1), den das Modell oben ausgibt — ist ein
     *               echter Titel, keine Kopie des Angles.
     * - newsletter: Betreff (erste Zeile)
     * - ad_copy:    Headline (zweite Zeile bei "Primary Text\nHeadline\n...")
     * - landing_page_headlines: Hero Headline (erste Zeile)
     * - linkedin_post & Rest: kurzer interner Label-Auszug aus dem Hook
     *   (kein Link-Detail; LinkedIn hat keinen separaten Headline-Slot).
     */
    private function deriveTitle(string $content, string $format, Angle $angle): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $content))));

        if ($format === 'blog_post') {
            $title = isset($lines[0]) && str_starts_with($lines[0], '#')
                ? ltrim($lines[0], '# ')
                : ($lines[0] ?? '');
            if ($title !== '') {
                return mb_substr($title, 0, 120);
            }
        }

        if (in_array($format, ['newsletter'], true)) {
            return mb_substr($lines[0] ?? mb_substr($angle->angle, 0, 80), 0, 120);
        }

        if ($format === 'ad_copy') {
            return mb_substr($lines[1] ?? $lines[0] ?? '', 0, 120);
        }

        if ($format === 'landing_page_headlines') {
            return mb_substr($lines[0] ?? '', 0, 120);
        }

        // LinkedIn & übrige: erster Satz/Hook als interner Label-Auszug
        $firstLine = $lines[0] ?? $angle->angle;
        $firstSentence = preg_split('/(?<=[.!?])\s+/', $firstLine)[0] ?? $firstLine;

        return mb_substr($firstSentence, 0, 80);
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
