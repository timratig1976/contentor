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
        $persona = null;
        if (!empty($validated['persona_id'])) {
            $persona = Persona::find($validated['persona_id']);
        } else {
            // Try to match by topic
            $personas = Persona::where('strategy_id', $strategy->id)->where('active', true)->get();
            foreach ($personas as $p) {
                foreach ((array) $p->topics as $topic) {
                    if (stripos($validated['input'], $topic) !== false) {
                        $persona = $p;
                        break 2;
                    }
                }
            }
            $persona ??= $personas->first();
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
        ]);

        $angle = Angle::with('strategy')->findOrFail($validated['angle_id']);
        $strategy = $angle->strategy;

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
                $personaCtx = [
                    'name' => $persona->name,
                    'role' => $persona->role,
                    'voice' => $persona->voice,
                    'tonality' => $persona->tonality,
                    'positioning' => $persona->positioning,
                    'core_statements' => $persona->core_statements,
                    'topics' => $persona->topics,
                    'content_attributes' => $persona->content_attributes,
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

        // Generate content via LLM mit Template + Brand Voice + Persona + Kanal-Regeln
        $content = $this->generateContentWithLLM($angle, $validated, $strategy, $strategyCtx, $personaCtx);

        // Enforce tone (with persona's tonality rules if available)
        $content = $this->rulesService->enforceTone($content, $validated['format'], $strategy, $strategyCtx);

        // Create content item
        $item = ContentItem::create([
            'strategy_id' => $strategy->id,
            'angle_id' => $angle->id,
            'type' => 'post',
            'format' => $validated['format'],
            'title' => mb_substr($angle->angle, 0, 80),
            'content' => $content,
            'status' => 'in_produktion',
            'icp' => $angle->icp,
            'pain_cluster' => $angle->pain_cluster,
            'statement_type' => $angle->statement_type,
            'owner' => $strategy->config['rules']['defaultOwner'] ?? $strategy->key,
        ]);

        // Auto-create media briefings
        $briefings = $this->mediaService->buildBriefings($validated['format'], [
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

        return response()->json($item->load(['strategy', 'angle', 'media']), 201);
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
            if ($text && strlen(trim($text)) > 20) {
                return trim($text);
            }
        } catch (\Throwable $e) {
            // Fallback unten
        }

        return $this->generateContent($angle, $params, $strategyCtx, $personaCtx);
    }

    /**
     * Baut den Prompt für die Content-Generierung aus Regelwerk + Kontext.
     */
    private function buildContentPrompt(Angle $angle, array $params, string $format, ?array $template, array $channelRules, array $brandVoice, ?array $personaCtx, Strategy $strategy): string
    {
        $lines = [];
        $lines[] = "Schreibe einen {$format} für folgenden Content-Angle.";
        $lines[] = "";
        $lines[] = "ANGLE (Kernaussage):\n{$angle->angle}";
        if ($angle->icp) {
            $lines[] = "ICP: {$angle->icp}";
        }
        if ($angle->pain_cluster) {
            $lines[] = "Pain-Cluster: {$angle->pain_cluster}";
        }

        if ($template) {
            $lines[] = "";
            $lines[] = "PATTERN: {$template['label']} — {$template['beschreibung']}";
            $lines[] = "Struktur: " . implode(' → ', $template['struktur']);
            $lines[] = "Beispiel-Hook: \"{$template['beispiel_hook']}\"";
        }

        if ($personaCtx) {
            $lines[] = "";
            $lines[] = "ZIEL-PERSONA: {$personaCtx['name']} ({$personaCtx['role']})";
            if (!empty($personaCtx['voice'])) {
                $lines[] = "Sprachstil der Persona: {$personaCtx['voice']}";
            }
            if (!empty($personaCtx['core_statements'])) {
                $lines[] = "Deren Überzeugungen: " . implode(' | ', array_slice((array) $personaCtx['core_statements'], 0, 3));
            }
        }

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
            $lines[] = "";
            $lines[] = "KANAL-REGELN:\n- " . implode("\n- ", $rulesText);
        }

        $voiceRules = $brandVoice['rules'] ?? [];
        if ($voiceRules) {
            $lines[] = "";
            $lines[] = "BRAND VOICE:\n- " . implode("\n- ", $voiceRules);
        }

        foreach (['metric', 'mechanism', 'proofs', 'cta'] as $k) {
            if (!empty($params[$k])) {
                $lines[] = "";
                $lines[] = strtoupper($k) . " (vorgegeben): {$params[$k]}";
            }
        }

        $hashtags = $strategy->hashtags ?? [];
        if ($format === 'linkedin_post' && $hashtags) {
            $lines[] = "";
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
