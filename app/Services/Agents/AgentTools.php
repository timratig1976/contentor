<?php

namespace App\Services\Agents;

use App\Models\Angle;
use App\Models\ContentItem;
use App\Models\ContentMedia;
use App\Models\Persona;
use App\Models\Source;
use App\Models\Strategy;
use App\Services\AgentContextService;
use App\Services\EdenAIWebService;
use App\Services\MediaBriefingService;
use App\Http\Controllers\Api\ContentController;
use Illuminate\Http\Request;

/**
 * All tool implementations and OpenAI-format tool definitions for the agents.
 *
 * Replaces content-agent/tools/api_tools.py — instead of HTTP calls back to
 * Laravel's own API, these are direct Eloquent / service calls.
 */
class AgentTools
{
    public function __construct(
        private AgentContextService $contextService,
        private EdenAIWebService $webService,
        private MediaBriefingService $mediaBriefingService,
    ) {}

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Register tools into the given registry based on which agent needs them.
     *
     * @param  string[]  $toolNames
     */
    public function register(ToolRegistry $registry, array $toolNames, string $strategy): void
    {
        foreach ($toolNames as $name) {
            $callable = $this->callableFor($name, $strategy);
            if ($callable) {
                $registry->register($name, $callable);
            }
        }
    }

    private function callableFor(string $name, string $strategy): ?callable
    {
        return match ($name) {
            'web_search'          => fn(string $query): array => $this->webService->search($query),
            'scrape_page'         => fn(string $url): array => $this->webService->scrape($url),
            'get_strategy_context'=> fn(?string $persona = null): array => $this->getStrategyContext($persona, $strategy),
            'create_source'       => fn(string $title, string $type, string $s = 'viscale', string $visibility = 'intern', ?string $file_ref = null, ?string $batch_key = null, ?string $url = null): array => $this->createSource($title, $type, $s, $visibility, $file_ref, $batch_key, $url),
            'list_sources'        => fn(string $s = 'viscale', ?string $type = null, ?string $batch_key = null, int $per_page = 50): array => $this->listSources($s, $type, $batch_key, $per_page),
            'get_source_angles'   => fn(string $source_id): array => $this->getSourceAngles($source_id),
            'create_angle'        => fn(string $angle, string $s = 'viscale', ?string $icp = null, ?string $pain_cluster = null, ?string $statement_type = null, ?string $source_id = null, ?string $batch_key = null, ?string $funnel = null, ?string $viscale_phase = null): array => $this->createAngle($angle, $s, $icp, $pain_cluster, $statement_type, $source_id, $batch_key, $funnel, $viscale_phase),
            'list_angles'         => fn(string $s = 'viscale', ?string $batch = null, ?string $icp = null, ?string $status = null, ?string $funnel = null, ?int $min_score = null, string $sort = 'ranking_score', string $direction = 'desc', int $per_page = 50): array => $this->listAngles($s, $batch, $icp, $status, $funnel, $min_score, $sort, $direction, $per_page),
            'update_angle'        => fn(string $angle_id, ?string $angle = null, ?string $icp = null, ?string $pain_cluster = null, ?string $statement_type = null, ?string $funnel = null, ?string $viscale_phase = null, ?string $status = null, ?int $r_zielgruppe = null, ?int $r_viscale_fit = null, ?int $r_schaerfe = null, ?int $r_timing = null, ?string $score_reasoning = null): array => $this->updateAngle($angle_id, array_filter(compact('angle','icp','pain_cluster','statement_type','funnel','viscale_phase','status','r_zielgruppe','r_viscale_fit','r_schaerfe','r_timing','score_reasoning'), fn($v) => $v !== null)),
            'get_batch_ranking'   => fn(string $batch_key, string $s = 'viscale', ?int $min_score = null): array => $this->getBatchRanking($batch_key, $s, $min_score),
            'create_content_idea' => fn(string $input_text, string $s = 'viscale', ?string $icp = null, ?string $source_type = null): array => $this->createContentIdea($input_text, $s, $icp, $source_type),
            'produce_content'     => fn(string $angle_id, string $format, ?string $pattern = null, ?string $persona_id = null, ?string $metric = null, ?string $mechanism = null, ?string $proofs = null, ?string $kpis = null, ?string $cta = null, ?int $variants_count = null, ?array $variant_patterns = null, string $s = 'viscale'): array => $this->produceContent($angle_id, $format, $pattern, $persona_id, $metric, $mechanism, $proofs, $kpis, $cta, $variants_count, $variant_patterns, $s),
            'revise_content'      => fn(string $content_id, string $feedback): array => $this->reviseContent($content_id, $feedback),
            'list_content'        => fn(string $s = 'viscale', ?string $type = null, ?string $status = null, ?string $icp = null, int $per_page = 50): array => $this->listContent($s, $type, $status, $icp, $per_page),
            'update_content'      => fn(string $content_id, ?string $title = null, ?string $content = null, ?string $status = null, ?string $format = null, ?string $owner = null, ?string $live_date = null, ?string $icp = null, ?string $persona_id = null): array => $this->updateContent($content_id, array_filter(compact('title','content','status','format','owner','live_date','icp','persona_id'), fn($v) => $v !== null)),
            'get_overview'        => fn(string $s = 'viscale'): array => $this->getOverview($s),
            'list_redaktionsplan' => fn(string $s = 'viscale', ?string $from_date = null, ?string $to_date = null, ?string $status = null, string $mode = 'list'): array => $this->listRedaktionsplan($s, $from_date, $to_date, $status, $mode),
            'create_plan_entry'   => fn(string $content_item_id, string $planned_date, string $s = 'viscale', ?string $channel = null, string $status = 'geplant', ?string $notes = null): array => $this->createPlanEntry($content_item_id, $planned_date, $s, $channel, $status, $notes),
            'get_strategy'        => fn(string $s = 'viscale', ?string $key = null): array => $this->getStrategy($s, $key),
            'save_strategy'       => fn(string $s, string $key, array $content): array => $this->saveStrategy($s, $key, $content),
            'create_media_briefing' => fn(string $item_id, string $media_type, ?string $prompt = null, ?array $generation_params = null, int $position = 0, ?string $notes = null, string $s = 'viscale'): array => $this->createMediaBriefing($item_id, $media_type, $prompt, $generation_params, $position, $notes, $s),
            default => null,
        };
    }

    // ─── Tool Definitions (OpenAI function-calling format) ────────────────────

    /** @return array<int,array> */
    public function definitionsFor(array $toolNames): array
    {
        $all = [
            'web_search' => [
                'name' => 'web_search',
                'description' => 'Search the web for a topic. Returns a list of relevant URLs and snippets.',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Search query']], 'required' => ['query']],
            ],
            'scrape_page' => [
                'name' => 'scrape_page',
                'description' => 'Scrape the full content of a URL.',
                'parameters' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'URL to scrape']], 'required' => ['url']],
            ],
            'get_strategy_context' => [
                'name' => 'get_strategy_context',
                'description' => 'Load the full campaign context (ICPs, pain clusters, tone, personas) for the current strategy.',
                'parameters' => ['type' => 'object', 'properties' => ['persona' => ['type' => 'string', 'description' => 'Optional persona name to focus on']]],
            ],
            'create_source' => [
                'name' => 'create_source',
                'description' => 'Create a new research source.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'description' => 'pdf, url, interview, intern, research'],
                    'visibility' => ['type' => 'string', 'default' => 'intern'],
                    'batch_key' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                ], 'required' => ['title', 'type']],
            ],
            'list_sources' => [
                'name' => 'list_sources',
                'description' => 'List all sources for the strategy.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string'],
                    'batch_key' => ['type' => 'string'],
                ]],
            ],
            'create_angle' => [
                'name' => 'create_angle',
                'description' => 'Create a new content angle.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'angle' => ['type' => 'string', 'description' => 'The angle text (max 200 chars)'],
                    'icp' => ['type' => 'string'],
                    'pain_cluster' => ['type' => 'string'],
                    'statement_type' => ['type' => 'string'],
                    'source_id' => ['type' => 'string'],
                    'batch_key' => ['type' => 'string'],
                    'funnel' => ['type' => 'string', 'description' => 'ToFu, MoFu, BoFu'],
                ], 'required' => ['angle']],
            ],
            'list_angles' => [
                'name' => 'list_angles',
                'description' => 'List all angles with optional filters.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'batch' => ['type' => 'string'],
                    'icp' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'min_score' => ['type' => 'integer'],
                    'sort' => ['type' => 'string', 'default' => 'ranking_score'],
                ]],
            ],
            'update_angle' => [
                'name' => 'update_angle',
                'description' => 'Update an angle including scoring criteria and score_reasoning.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'angle_id' => ['type' => 'string'],
                    'angle' => ['type' => 'string'],
                    'icp' => ['type' => 'string'],
                    'pain_cluster' => ['type' => 'string'],
                    'statement_type' => ['type' => 'string'],
                    'funnel' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'r_zielgruppe' => ['type' => 'integer', 'description' => '1-3'],
                    'r_viscale_fit' => ['type' => 'integer', 'description' => '1-3'],
                    'r_schaerfe' => ['type' => 'integer', 'description' => '1-3'],
                    'r_timing' => ['type' => 'integer', 'description' => '1-3'],
                    'score_reasoning' => ['type' => 'string'],
                ], 'required' => ['angle_id']],
            ],
            'get_batch_ranking' => [
                'name' => 'get_batch_ranking',
                'description' => 'Get the full ranking of all angles in a batch.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'batch_key' => ['type' => 'string'],
                    'min_score' => ['type' => 'integer'],
                ], 'required' => ['batch_key']],
            ],
            'create_content_idea' => [
                'name' => 'create_content_idea',
                'description' => 'Create a new content idea.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'input_text' => ['type' => 'string'],
                    'icp' => ['type' => 'string'],
                    'source_type' => ['type' => 'string'],
                ], 'required' => ['input_text']],
            ],
            'produce_content' => [
                'name' => 'produce_content',
                'description' => 'Produce content from an angle using the backend generation pipeline.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'angle_id' => ['type' => 'string'],
                    'format' => ['type' => 'string', 'description' => 'linkedin_post, ad_copy, newsletter, landing_page_headlines, blog_post'],
                    'pattern' => ['type' => 'string', 'description' => 'contrarian_take, data_drop, mistake_post, framework'],
                    'persona_id' => ['type' => 'string'],
                    'metric' => ['type' => 'string'],
                    'mechanism' => ['type' => 'string'],
                    'proofs' => ['type' => 'string'],
                    'kpis' => ['type' => 'string'],
                    'cta' => ['type' => 'string'],
                    'variants_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                ], 'required' => ['angle_id', 'format']],
            ],
            'revise_content' => [
                'name' => 'revise_content',
                'description' => 'Mark a content item for revision based on review feedback.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'content_id' => ['type' => 'string'],
                    'feedback' => ['type' => 'string'],
                ], 'required' => ['content_id', 'feedback']],
            ],
            'list_content' => [
                'name' => 'list_content',
                'description' => 'List all content items.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'type' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'description' => 'idee, angle, in_produktion, review, geplant, live, verworfen'],
                    'icp' => ['type' => 'string'],
                ]],
            ],
            'update_content' => [
                'name' => 'update_content',
                'description' => 'Update a content item.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'content_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                    'owner' => ['type' => 'string'],
                    'live_date' => ['type' => 'string'],
                    'icp' => ['type' => 'string'],
                    'persona_id' => ['type' => 'string'],
                ], 'required' => ['content_id']],
            ],
            'get_overview' => [
                'name' => 'get_overview',
                'description' => 'Get dashboard overview with stats, top angles and upcoming plan entries.',
                'parameters' => ['type' => 'object', 'properties' => (object)[]],
            ],
            'list_redaktionsplan' => [
                'name' => 'list_redaktionsplan',
                'description' => 'List editorial plan entries.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'from_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'to_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'status' => ['type' => 'string'],
                ]],
            ],
            'create_plan_entry' => [
                'name' => 'create_plan_entry',
                'description' => 'Create an editorial plan entry.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'content_item_id' => ['type' => 'string'],
                    'planned_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'channel' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'default' => 'geplant'],
                    'notes' => ['type' => 'string'],
                ], 'required' => ['content_item_id', 'planned_date']],
            ],
            'get_strategy' => [
                'name' => 'get_strategy',
                'description' => 'Get strategy data (brand voice, channel rules, etc.).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Optional: brand_voice, channel_rules, etc. — returns all if omitted'],
                ]],
            ],
            'save_strategy' => [
                'name' => 'save_strategy',
                'description' => 'Save or update a strategy entry.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'key' => ['type' => 'string'],
                    'content' => ['type' => 'object'],
                ], 'required' => ['key', 'content']],
            ],
            'create_media_briefing' => [
                'name' => 'create_media_briefing',
                'description' => 'Create a media briefing for a content item.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'item_id' => ['type' => 'string'],
                    'media_type' => ['type' => 'string'],
                    'prompt' => ['type' => 'string'],
                    'generation_params' => ['type' => 'object'],
                    'position' => ['type' => 'integer', 'default' => 0],
                    'notes' => ['type' => 'string'],
                ], 'required' => ['item_id', 'media_type']],
            ],
        ];

        $defs = [];
        foreach ($toolNames as $name) {
            if (isset($all[$name])) {
                $defs[] = ['type' => 'function', 'function' => $all[$name]];
            }
        }
        return $defs;
    }

    // ─── Implementations ──────────────────────────────────────────────────────

    private function getStrategyContext(?string $persona, string $strategy): array
    {
        $personaId = null;
        if ($persona) {
            $personaId = Persona::where('name', 'like', "%{$persona}%")->value('id');
        }
        return ['context' => $this->contextService->build($strategy, $personaId)];
    }

    private function createSource(string $title, string $type, string $strategy, string $visibility, ?string $fileRef, ?string $batchKey, ?string $url): array
    {
        $strategyModel = Strategy::where('key', $strategy)->firstOrFail();
        $source = Source::create([
            'title' => $title,
            'type' => $type,
            'strategy_id' => $strategyModel->id,
            'visibility' => $visibility,
            'file_ref' => $fileRef,
            'batch_key' => $batchKey,
            'url' => $url,
        ]);
        return $source->toArray();
    }

    private function listSources(string $strategy, ?string $type, ?string $batchKey, int $perPage): array
    {
        $strategyModel = Strategy::where('key', $strategy)->first();
        $query = Source::query();
        if ($strategyModel) $query->where('strategy_id', $strategyModel->id);
        if ($type) $query->where('type', $type);
        if ($batchKey) $query->where('batch_key', $batchKey);
        return $query->latest()->paginate($perPage)->toArray();
    }

    private function getSourceAngles(string $sourceId): array
    {
        return Angle::where('source_id', $sourceId)->get()->toArray();
    }

    private function createAngle(string $angle, string $strategy, ?string $icp, ?string $painCluster, ?string $statementType, ?string $sourceId, ?string $batchKey, ?string $funnel, ?string $viscalePhase): array
    {
        $strategyModel = Strategy::where('key', $strategy)->firstOrFail();
        $angleModel = Angle::create([
            'angle' => $angle,
            'strategy_id' => $strategyModel->id,
            'icp' => $icp,
            'pain_cluster' => $painCluster,
            'statement_type' => $statementType,
            'source_id' => $sourceId,
            'batch_key' => $batchKey,
            'funnel' => $funnel,
            'viscale_phase' => $viscalePhase,
        ]);
        return $angleModel->toArray();
    }

    private function listAngles(string $strategy, ?string $batch, ?string $icp, ?string $status, ?string $funnel, ?int $minScore, string $sort, string $direction, int $perPage): array
    {
        $strategyModel = Strategy::where('key', $strategy)->first();
        $query = Angle::with(['strategy', 'source']);
        if ($strategyModel) $query->where('strategy_id', $strategyModel->id);
        if ($batch) $query->where('batch_key', $batch);
        if ($icp) $query->where('icp', $icp);
        if ($status) $query->where('status', $status);
        if ($funnel) $query->where('funnel', $funnel);
        if ($minScore !== null) $query->where('ranking_score', '>=', $minScore);
        return $query->orderBy($sort, $direction)->paginate($perPage)->toArray();
    }

    private function updateAngle(string $angleId, array $fields): array
    {
        $angle = Angle::findOrFail($angleId);
        $angle->fill($fields);
        if (isset($fields['r_zielgruppe']) || isset($fields['r_viscale_fit']) || isset($fields['r_schaerfe']) || isset($fields['r_timing'])) {
            $angle->updateRanking();
        } else {
            $angle->save();
        }
        return $angle->fresh()->toArray();
    }

    private function getBatchRanking(string $batchKey, string $strategy, ?int $minScore): array
    {
        $strategyModel = Strategy::where('key', $strategy)->first();
        $query = Angle::where('batch_key', $batchKey);
        if ($strategyModel) $query->where('strategy_id', $strategyModel->id);
        if ($minScore !== null) $query->where('ranking_score', '>=', $minScore);
        return $query->orderBy('ranking_score', 'desc')->get()->toArray();
    }

    private function createContentIdea(string $inputText, string $strategy, ?string $icp, ?string $sourceType): array
    {
        $request = new Request([
            'input' => $inputText,
            'strategy' => $strategy,
            'icp' => $icp,
            'source_type' => $sourceType,
        ]);
        $controller = app(ContentController::class);
        $response = $controller->storeIdee($request);
        return json_decode($response->getContent(), true);
    }

    private function produceContent(string $angleId, string $format, ?string $pattern, ?string $personaId, ?string $metric, ?string $mechanism, ?string $proofs, ?string $kpis, ?string $cta, ?int $variantsCount, ?array $variantPatterns, string $strategy): array
    {
        $request = new Request(array_filter([
            'angle_id' => $angleId,
            'format' => $format,
            'pattern' => $pattern,
            'persona_id' => $personaId,
            'metric' => $metric,
            'mechanism' => $mechanism,
            'proofs' => $proofs,
            'kpis' => $kpis,
            'cta' => $cta,
            'variants_count' => $variantsCount,
            'variant_patterns' => $variantPatterns,
            'strategy' => $strategy,
        ], fn($v) => $v !== null));

        $controller = app(ContentController::class);
        $response = $controller->produzieren($request);
        return json_decode($response->getContent(), true);
    }

    private function reviseContent(string $contentId, string $feedback): array
    {
        $item = ContentItem::findOrFail($contentId);
        $item->update(['status' => 'in_produktion']);
        return ['revised' => $contentId, 'feedback' => $feedback, 'status' => 'in_produktion'];
    }

    private function listContent(string $strategy, ?string $type, ?string $status, ?string $icp, int $perPage): array
    {
        $strategyModel = Strategy::where('key', $strategy)->first();
        $query = ContentItem::with(['strategy', 'angle', 'media']);
        if ($strategyModel) $query->where('strategy_id', $strategyModel->id);
        if ($type) $query->where('type', $type);
        if ($status) $query->where('status', $status);
        if ($icp) $query->where('icp', $icp);
        return $query->latest()->paginate($perPage)->toArray();
    }

    private function updateContent(string $contentId, array $fields): array
    {
        $item = ContentItem::findOrFail($contentId);
        $item->update($fields);
        return $item->fresh()->toArray();
    }

    private function getOverview(string $strategy): array
    {
        $strategyModel = Strategy::where('key', $strategy)->first();
        if (!$strategyModel) return ['error' => "Strategy '{$strategy}' not found."];

        return [
            'total_angles' => Angle::where('strategy_id', $strategyModel->id)->count(),
            'approved_angles' => Angle::where('strategy_id', $strategyModel->id)->where('status', 'approved')->count(),
            'content_items' => ContentItem::where('strategy_id', $strategyModel->id)->count(),
            'in_review' => ContentItem::where('strategy_id', $strategyModel->id)->where('status', 'review')->count(),
            'planned' => ContentItem::where('strategy_id', $strategyModel->id)->where('status', 'geplant')->count(),
            'top_angles' => Angle::where('strategy_id', $strategyModel->id)->orderBy('ranking_score', 'desc')->limit(5)->get(['id', 'angle', 'ranking_score', 'status'])->toArray(),
        ];
    }

    private function listRedaktionsplan(string $strategy, ?string $fromDate, ?string $toDate, ?string $status, string $mode): array
    {
        $query = \App\Models\RedaktionsplanEntry::with('contentItem');
        if ($fromDate) $query->where('planned_date', '>=', $fromDate);
        if ($toDate) $query->where('planned_date', '<=', $toDate);
        if ($status) $query->where('status', $status);
        return $query->orderBy('planned_date')->get()->toArray();
    }

    private function createPlanEntry(string $contentItemId, string $plannedDate, string $strategy, ?string $channel, string $status, ?string $notes): array
    {
        $strategyModel = Strategy::where('key', $strategy)->firstOrFail();
        $entry = \App\Models\RedaktionsplanEntry::create([
            'content_item_id' => $contentItemId,
            'planned_date' => $plannedDate,
            'strategy_id' => $strategyModel->id,
            'channel' => $channel,
            'status' => $status,
            'notes' => $notes,
        ]);
        return $entry->toArray();
    }

    private function getStrategy(string $strategy, ?string $key): array
    {
        $strategyModel = Strategy::where('key', $strategy)->firstOrFail();
        $content = [];
        foreach ($strategyModel->contentStrategies as $s) {
            $content[$s->key] = $s->content;
        }
        if ($key !== null) {
            return $content[$key] ?? ['error' => "Key '{$key}' not found."];
        }
        return $content;
    }

    private function saveStrategy(string $strategy, string $key, array $content): array
    {
        $strategyModel = Strategy::where('key', $strategy)->firstOrFail();
        \App\Models\ContentStrategy::updateOrCreate(
            ['strategy_id' => $strategyModel->id, 'key' => $key],
            ['content' => $content]
        );
        return ['saved' => true, 'strategy' => $strategy, 'key' => $key];
    }

    private function createMediaBriefing(string $itemId, string $mediaType, ?string $prompt, ?array $generationParams, int $position, ?string $notes, string $strategy): array
    {
        $item = ContentItem::findOrFail($itemId);
        $briefing = ContentMedia::create([
            'content_item_id' => $itemId,
            'type' => $mediaType,
            'position' => $position,
            'briefing' => [
                'prompt_hint' => $prompt,
                'generation_params' => $generationParams ?? [],
                'notes' => $notes,
            ],
        ]);
        return $briefing->toArray();
    }
}
