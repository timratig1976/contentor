<?php

namespace App\Neuron\Tools;

use App\Services\Agents\AgentTools;
use App\Services\Agents\ToolRegistry;
use App\Services\EdenAIWebService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * ContentorToolkit — Wraps existing AgentTools methods into NeuronAI Tools.
 * Adapts between NeuronAI's Tool calling convention and our internal Eloquent/Service tools.
 * Supports test mode (angles are kept as draft proposals for user approval instead of filling DB).
 */
class ContentorToolkit
{
    private AgentTools $agentTools;
    private string $strategyKey;
    private bool $testMode;
    private ?int $defaultPersonaId;
    private ?\Closure $toolCallLogger;
    private array $proposedAngles = [];
    private array $proposedSources = [];

    public function __construct(string $strategyKey = 'viscale', bool $testMode = true, ?callable $toolCallLogger = null, ?int $defaultPersonaId = null)
    {
        $this->agentTools = app(AgentTools::class);
        $this->strategyKey = $strategyKey;
        $this->testMode = $testMode;
        $this->defaultPersonaId = $defaultPersonaId;
        $this->toolCallLogger = $toolCallLogger !== null ? \Closure::fromCallable($toolCallLogger) : null;
    }

    public static function make(string $strategyKey = 'viscale', bool $testMode = true, ?callable $toolCallLogger = null, ?int $defaultPersonaId = null): static
    {
        return new static($strategyKey, $testMode, $toolCallLogger, $defaultPersonaId);
    }

    public function getProposedAngles(): array
    {
        return $this->proposedAngles;
    }

    public function getProposedSources(): array
    {
        return $this->proposedSources;
    }

    public function setProposedAngles(array $angles): void
    {
        $this->proposedAngles = $angles;
    }

    /**
     * Executes a tool callable with latency timing and invokes the logging hook.
     */
    private function executeWithLogging(string $toolName, array $args, callable $action): string
    {
        $start = microtime(true);
        $error = null;
        $result = null;

        try {
            $result = $action();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $result = json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($this->toolCallLogger) {
            $decodedResult = json_decode($result, true) ?? $result;
            ($this->toolCallLogger)([
                'tool' => $toolName,
                'input' => $args,
                'output' => $decodedResult,
                'duration_ms' => $durationMs,
                'error' => $error,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        return $result;
    }

    /**
     * @return Tool[]
     */
    public function tools(): array
    {
        return [
            $this->webSearchTool(),
            $this->scrapePageTool(),
            $this->getStrategyContextTool(),
            $this->createSourceTool(),
            $this->listSourcesTool(),
            $this->createAngleTool(),
            $this->listAnglesTool(),
            $this->updateAngleTool(),
            $this->getBatchRankingTool(),
            $this->produceContentTool(),
            $this->reviseContentTool(),
            $this->listContentTool(),
            $this->updateContentTool(),
            $this->getOverviewTool(),
        ];
    }

    private function webSearchTool(): Tool
    {
        return Tool::make('web_search', 'Searches the web for articles, data, and competitors.')
            ->addProperty(ToolProperty::make('query', PropertyType::STRING, 'Search query', true))
            ->setCallable(function (string $query): string {
                return $this->executeWithLogging('web_search', ['query' => $query], function () use ($query) {
                    $res = app(EdenAIWebService::class)->search($query);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function scrapePageTool(): Tool
    {
        return Tool::make('scrape_page', 'Scrapes text content from a given URL.')
            ->addProperty(ToolProperty::make('url', PropertyType::STRING, 'The web URL to scrape', true))
            ->setCallable(function (string $url): string {
                return $this->executeWithLogging('scrape_page', ['url' => $url], function () use ($url) {
                    $res = app(EdenAIWebService::class)->scrape($url);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function getStrategyContextTool(): Tool
    {
        return Tool::make('get_strategy_context', 'Returns full strategy context: ICPs, clusters, rules, and personas. Call this first.')
            ->addProperty(ToolProperty::make('persona', PropertyType::STRING, 'Optional persona name', false))
            ->setCallable(function (?string $persona = null): string {
                return $this->executeWithLogging('get_strategy_context', ['persona' => $persona], function () use ($persona) {
                    $contextService = app(\App\Services\AgentContextService::class);
                    $personaId = $this->defaultPersonaId;
                    if (!empty($persona)) {
                        $foundId = \App\Models\Persona::where('name', 'like', "%{$persona}%")->value('id');
                        if ($foundId) {
                            $personaId = $foundId;
                        }
                    }
                    $ctx = $contextService->build($this->strategyKey, $personaId);
                    return json_encode([
                        'context' => $ctx,
                        'strategy' => $this->strategyKey,
                        'persona_id' => $personaId,
                    ], JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function createSourceTool(): Tool
    {
        return Tool::make('create_source', 'Creates a research source in the database.')
            ->addProperty(ToolProperty::make('title', PropertyType::STRING, 'Source title', true))
            ->addProperty(ToolProperty::make('type', PropertyType::STRING, 'url | research | interview | intern | pdf', true))
            ->addProperty(ToolProperty::make('batch_key', PropertyType::STRING, 'Batch key for grouping', false))
            ->addProperty(ToolProperty::make('url', PropertyType::STRING, 'URL if type is url', false))
            ->setCallable(function (string $title, string $type, ?string $batch_key = null, ?string $url = null): string {
                return $this->executeWithLogging('create_source', [
                    'title' => $title,
                    'type' => $type,
                    'batch_key' => $batch_key,
                    'url' => $url,
                ], function () use ($title, $type, $batch_key, $url) {
                    if ($this->testMode) {
                        $srcId = 'SRC-PROP-' . strtoupper(substr(uniqid(), -5));
                        $src = [
                            'id' => $srcId,
                            'title' => $title,
                            'type' => $type,
                            'url' => $url,
                            'batch_key' => $batch_key,
                            'status' => 'proposed',
                            'test_mode' => true,
                            'created_at' => now()->toIso8601String(),
                        ];
                        $this->proposedSources[$srcId] = $src;
                        return json_encode($src, JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['create_source'], $this->strategyKey);
                    $res = $registry->execute('create_source', [
                        'title' => $title,
                        'type' => $type,
                        's' => $this->strategyKey,
                        'visibility' => 'intern',
                        'file_ref' => null,
                        'batch_key' => $batch_key,
                        'url' => $url,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function listSourcesTool(): Tool
    {
        return Tool::make('list_sources', 'Lists research sources for the current strategy.')
            ->addProperty(ToolProperty::make('batch_key', PropertyType::STRING, 'Filter by batch key', false))
            ->setCallable(function (?string $batch_key = null): string {
                return $this->executeWithLogging('list_sources', ['batch_key' => $batch_key], function () use ($batch_key) {
                    if ($this->testMode && !empty($this->proposedSources)) {
                        return json_encode([
                            'data' => array_values($this->proposedSources),
                            'total' => count($this->proposedSources),
                            'test_mode' => true,
                        ], JSON_UNESCAPED_UNICODE);
                    }
                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['list_sources'], $this->strategyKey);
                    $res = $registry->execute('list_sources', ['s' => $this->strategyKey, 'batch_key' => $batch_key]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function createAngleTool(): Tool
    {
        return Tool::make('create_angle', 'Creates a new strategic content angle with these, mechanismus, implikation, and beleg.')
            ->addProperty(ToolProperty::make('these', PropertyType::STRING, 'Kernbehauptung, 1 Satz, neutral und sachlich', false))
            ->addProperty(ToolProperty::make('mechanismus', PropertyType::STRING, 'Warum das so ist (Ursache → Wirkung)', false))
            ->addProperty(ToolProperty::make('implikation', PropertyType::STRING, 'Was der ICP daraus ändern muss', false))
            ->addProperty(ToolProperty::make('beleg', PropertyType::STRING, 'Trigger, Einwand oder Kundenzitat aus dem Kontext', false))
            ->addProperty(ToolProperty::make('icp', PropertyType::STRING, 'Target ICP: ICP-1 | ICP-2 | ICP-3', false))
            ->addProperty(ToolProperty::make('pain_cluster', PropertyType::STRING, 'E3-01 | E3-02 | E3-03 | E3-05 | E3-06 | E1-02', false))
            ->addProperty(ToolProperty::make('funnel', PropertyType::STRING, 'ToFu | MoFu | BoFu', false))
            ->addProperty(ToolProperty::make('angle', PropertyType::STRING, 'Fallback für These', false))
            ->addProperty(ToolProperty::make('statement_type', PropertyType::STRING, 'Optional statement type', false))
            ->addProperty(ToolProperty::make('batch_key', PropertyType::STRING, 'Batch key', false))
            ->addProperty(ToolProperty::make('source_id', PropertyType::STRING, 'Source ID', false))
            ->setCallable(function (
                ?string $these = null,
                ?string $mechanismus = null,
                ?string $implikation = null,
                ?string $beleg = null,
                ?string $icp = null,
                ?string $pain_cluster = null,
                ?string $funnel = null,
                ?string $angle = null,
                ?string $statement_type = null,
                ?string $batch_key = null,
                ?string $source_id = null
            ): string {
                $mainThese = !empty($these) ? $these : ($angle ?? '');
                return $this->executeWithLogging('create_angle', [
                    'these'          => $mainThese,
                    'mechanismus'    => $mechanismus,
                    'implikation'    => $implikation,
                    'beleg'          => $beleg,
                    'icp'            => $icp,
                    'pain_cluster'   => $pain_cluster,
                    'funnel'         => $funnel,
                    'statement_type' => $statement_type,
                    'batch_key'      => $batch_key,
                    'source_id'      => $source_id,
                ], function () use ($mainThese, $mechanismus, $implikation, $beleg, $icp, $pain_cluster, $funnel, $statement_type, $batch_key, $source_id) {
                    if ($this->testMode) {
                        $propId = 'PROP-' . strtoupper(substr(uniqid(), -5));
                        $item = [
                            'id'             => $propId,
                            'these'          => $mainThese,
                            'angle'          => $mainThese, // backward compat
                            'mechanismus'    => $mechanismus,
                            'implikation'    => $implikation,
                            'beleg'          => $beleg,
                            'strategy'       => $this->strategyKey,
                            'icp'            => $icp,
                            'pain_cluster'   => $pain_cluster,
                            'statement_type' => $statement_type,
                            'funnel'         => $funnel,
                            'source_id'      => $source_id,
                            'batch_key'      => $batch_key,
                            'status'         => 'pending_approval',
                            'r_zielgruppe'   => null,
                            'r_viscale_fit'  => null,
                            'r_schaerfe'     => null,
                            'r_timing'       => null,
                            'ranking_score'  => null,
                            'score_reasoning'=> null,
                            'approved'       => false,
                            'created_at'     => now()->toIso8601String(),
                        ];
                        $this->proposedAngles[$propId] = $item;

                        return json_encode([
                            'id'        => $propId,
                            'these'     => $mainThese,
                            'status'    => 'pending_approval',
                            'test_mode' => true,
                            'notice'    => 'TEST-MODUS: Strategischer Angle als Entwurf erfasst.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $fullAngleText = $mainThese;
                    if ($mechanismus) $fullAngleText .= "\n\nMechanismus: " . $mechanismus;
                    if ($implikation) $fullAngleText .= "\nImplikation: " . $implikation;
                    if ($beleg)       $fullAngleText .= "\nBeleg: " . $beleg;

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['create_angle'], $this->strategyKey);
                    $res = $registry->execute('create_angle', [
                        'angle'          => $fullAngleText,
                        's'              => $this->strategyKey,
                        'icp'            => $icp,
                        'pain_cluster'   => $pain_cluster,
                        'statement_type' => $statement_type,
                        'source_id'      => $source_id,
                        'batch_key'      => $batch_key,
                        'funnel'         => $funnel,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function listAnglesTool(): Tool
    {
        return Tool::make('list_angles', 'Lists angles for the strategy, optionally filtered.')
            ->addProperty(ToolProperty::make('status', PropertyType::STRING, 'Filter status', false))
            ->addProperty(ToolProperty::make('icp', PropertyType::STRING, 'Filter ICP', false))
            ->addProperty(ToolProperty::make('funnel', PropertyType::STRING, 'Filter funnel', false))
            ->addProperty(ToolProperty::make('min_score', PropertyType::INTEGER, 'Minimum score', false))
            ->setCallable(function (?string $status = null, ?string $icp = null, ?string $funnel = null, ?int $min_score = null): string {
                return $this->executeWithLogging('list_angles', [
                    'status' => $status,
                    'icp' => $icp,
                    'funnel' => $funnel,
                    'min_score' => $min_score,
                ], function () use ($status, $icp, $funnel, $min_score) {
                    if ($this->testMode && !empty($this->proposedAngles)) {
                        $list = array_values($this->proposedAngles);
                        if ($icp) {
                            $list = array_values(array_filter($list, fn($a) => ($a['icp'] ?? '') === $icp));
                        }
                        if ($funnel) {
                            $list = array_values(array_filter($list, fn($a) => ($a['funnel'] ?? '') === $funnel));
                        }
                        return json_encode([
                            'data' => $list,
                            'total' => count($list),
                            'test_mode' => true,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['list_angles'], $this->strategyKey);
                    $res = $registry->execute('list_angles', [
                        's' => $this->strategyKey,
                        'icp' => $icp,
                        'status' => $status,
                        'funnel' => $funnel,
                        'min_score' => $min_score,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function updateAngleTool(): Tool
    {
        return Tool::make('update_angle', 'Updates an existing angle with scores, status or metadata.')
            ->addProperty(ToolProperty::make('angle_id', PropertyType::STRING, 'Angle ID (ANG-xxx or PROP-xxx)', true))
            ->addProperty(ToolProperty::make('status', PropertyType::STRING, 'new status (approved, verworfen, etc.)', false))
            ->addProperty(ToolProperty::make('r_zielgruppe', PropertyType::INTEGER, 'Score 1-3', false))
            ->addProperty(ToolProperty::make('r_viscale_fit', PropertyType::INTEGER, 'Score 1-3', false))
            ->addProperty(ToolProperty::make('r_schaerfe', PropertyType::INTEGER, 'Score 1-3', false))
            ->addProperty(ToolProperty::make('r_timing', PropertyType::INTEGER, 'Score 1-3', false))
            ->addProperty(ToolProperty::make('score_reasoning', PropertyType::STRING, 'Reasoning', false))
            ->addProperty(ToolProperty::make('pain_cluster', PropertyType::STRING, 'Cluster assignment', false))
            ->addProperty(ToolProperty::make('funnel', PropertyType::STRING, 'ToFu | MoFu | BoFu', false))
            ->setCallable(function (string $angle_id, ?string $status = null, ?int $r_zielgruppe = null, ?int $r_viscale_fit = null, ?int $r_schaerfe = null, ?int $r_timing = null, ?string $score_reasoning = null, ?string $pain_cluster = null, ?string $funnel = null): string {
                return $this->executeWithLogging('update_angle', [
                    'angle_id' => $angle_id,
                    'status' => $status,
                    'r_zielgruppe' => $r_zielgruppe,
                    'r_viscale_fit' => $r_viscale_fit,
                    'r_schaerfe' => $r_schaerfe,
                    'r_timing' => $r_timing,
                    'score_reasoning' => $score_reasoning,
                    'pain_cluster' => $pain_cluster,
                    'funnel' => $funnel,
                ], function () use ($angle_id, $status, $r_zielgruppe, $r_viscale_fit, $r_schaerfe, $r_timing, $score_reasoning, $pain_cluster, $funnel) {
                    if ($this->testMode && isset($this->proposedAngles[$angle_id])) {
                        $cur = $this->proposedAngles[$angle_id];
                        $zg = $r_zielgruppe ?? $cur['r_zielgruppe'];
                        $fit = $r_viscale_fit ?? $cur['r_viscale_fit'];
                        $sch = $r_schaerfe ?? $cur['r_schaerfe'];
                        $tim = $r_timing ?? $cur['r_timing'];
                        $score = ($zg !== null && $fit !== null && $sch !== null && $tim !== null) ? ($zg + $fit + $sch + $tim) : null;

                        $this->proposedAngles[$angle_id] = array_merge($cur, [
                            'r_zielgruppe' => $zg,
                            'r_viscale_fit' => $fit,
                            'r_schaerfe' => $sch,
                            'r_timing' => $tim,
                            'ranking_score' => $score,
                            'score_reasoning' => $score_reasoning ?? $cur['score_reasoning'],
                            'pain_cluster' => $pain_cluster ?? $cur['pain_cluster'],
                            'funnel' => $funnel ?? $cur['funnel'],
                            'status' => $status ?? ($score ? 'bewertet' : $cur['status']),
                        ]);

                        return json_encode([
                            'success' => true,
                            'id' => $angle_id,
                            'updated' => $this->proposedAngles[$angle_id],
                            'test_mode' => true,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['update_angle'], $this->strategyKey);
                    $res = $registry->execute('update_angle', [
                        'angle_id' => $angle_id,
                        'status' => $status,
                        'r_zielgruppe' => $r_zielgruppe,
                        'r_viscale_fit' => $r_viscale_fit,
                        'r_schaerfe' => $r_schaerfe,
                        'r_timing' => $r_timing,
                        'score_reasoning' => $score_reasoning,
                        'pain_cluster' => $pain_cluster,
                        'funnel' => $funnel,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function getBatchRankingTool(): Tool
    {
        return Tool::make('get_batch_ranking', 'Returns ranked list of angles in a batch, sorted by score.')
            ->addProperty(ToolProperty::make('batch_key', PropertyType::STRING, 'Batch key', true))
            ->setCallable(function (string $batch_key): string {
                return $this->executeWithLogging('get_batch_ranking', ['batch_key' => $batch_key], function () use ($batch_key) {
                    if ($this->testMode && !empty($this->proposedAngles)) {
                        $sorted = array_values($this->proposedAngles);
                        usort($sorted, fn($a, $b) => ($b['ranking_score'] ?? 0) <=> ($a['ranking_score'] ?? 0));
                        return json_encode([
                            'ranked_angles' => $sorted,
                            'test_mode' => true,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['get_batch_ranking'], $this->strategyKey);
                    $res = $registry->execute('get_batch_ranking', ['batch_key' => $batch_key, 's' => $this->strategyKey]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function produceContentTool(): Tool
    {
        return Tool::make('produce_content', 'Produces a full post or content item from an angle.')
            ->addProperty(ToolProperty::make('angle_id', PropertyType::STRING, 'Angle ID (ANG-xxx or PROP-xxx)', true))
            ->addProperty(ToolProperty::make('format', PropertyType::STRING, 'linkedin_post | blog_post | newsletter', true))
            ->addProperty(ToolProperty::make('persona_id', PropertyType::STRING, 'Optional Persona ID', false))
            ->addProperty(ToolProperty::make('pattern', PropertyType::STRING, 'Optional pattern name', false))
            ->setCallable(function (string $angle_id, string $format, ?string $persona_id = null, ?string $pattern = null): string {
                return $this->executeWithLogging('produce_content', [
                    'angle_id' => $angle_id,
                    'format' => $format,
                    'persona_id' => $persona_id,
                    'pattern' => $pattern,
                ], function () use ($angle_id, $format, $persona_id, $pattern) {
                    if ($this->testMode && str_starts_with($angle_id, 'PROP-')) {
                        $ang = $this->proposedAngles[$angle_id] ?? ['angle' => 'Thema Entwurf'];
                        return json_encode([
                            'content_id' => 'CNT-PREVIEW-' . strtoupper(substr(uniqid(), -4)),
                            'angle_id' => $angle_id,
                            'format' => $format,
                            'status' => 'draft_preview',
                            'test_mode' => true,
                            'content' => "Entwurf für {$format} basierend auf Thesis: \"{$ang['angle']}\"",
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['produce_content'], $this->strategyKey);
                    $res = $registry->execute('produce_content', [
                        'angle_id' => $angle_id,
                        'format' => $format,
                        'persona_id' => $persona_id,
                        'pattern' => $pattern,
                        's' => $this->strategyKey,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function reviseContentTool(): Tool
    {
        return Tool::make('revise_content', 'Revises existing content based on feedback.')
            ->addProperty(ToolProperty::make('content_id', PropertyType::STRING, 'Content item ID (CNT-xxx)', true))
            ->addProperty(ToolProperty::make('feedback', PropertyType::STRING, 'Revision instructions/feedback', true))
            ->setCallable(function (string $content_id, string $feedback): string {
                return $this->executeWithLogging('revise_content', [
                    'content_id' => $content_id,
                    'feedback' => $feedback,
                ], function () use ($content_id, $feedback) {
                    if ($this->testMode && str_starts_with($content_id, 'CNT-PREVIEW-')) {
                        return json_encode([
                            'content_id' => $content_id,
                            'status' => 'revised_preview',
                            'feedback' => $feedback,
                            'test_mode' => true,
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['revise_content'], $this->strategyKey);
                    $res = $registry->execute('revise_content', ['content_id' => $content_id, 'feedback' => $feedback]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function listContentTool(): Tool
    {
        return Tool::make('list_content', 'Lists content items for the current strategy.')
            ->addProperty(ToolProperty::make('status', PropertyType::STRING, 'Filter status', false))
            ->addProperty(ToolProperty::make('icp', PropertyType::STRING, 'Filter ICP', false))
            ->setCallable(function (?string $status = null, ?string $icp = null): string {
                return $this->executeWithLogging('list_content', ['status' => $status, 'icp' => $icp], function () use ($status, $icp) {
                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['list_content'], $this->strategyKey);
                    $res = $registry->execute('list_content', ['s' => $this->strategyKey, 'status' => $status, 'icp' => $icp]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function updateContentTool(): Tool
    {
        return Tool::make('update_content', 'Updates a content item in the database.')
            ->addProperty(ToolProperty::make('content_id', PropertyType::STRING, 'Content ID (CNT-xxx)', true))
            ->addProperty(ToolProperty::make('status', PropertyType::STRING, 'Status (review, geplant, live, etc.)', false))
            ->addProperty(ToolProperty::make('content', PropertyType::STRING, 'New content text', false))
            ->setCallable(function (string $content_id, ?string $status = null, ?string $content = null): string {
                return $this->executeWithLogging('update_content', [
                    'content_id' => $content_id,
                    'status' => $status,
                    'content' => $content,
                ], function () use ($content_id, $status, $content) {
                    if ($this->testMode && str_starts_with($content_id, 'CNT-PREVIEW-')) {
                        return json_encode(['success' => true, 'id' => $content_id, 'test_mode' => true], JSON_UNESCAPED_UNICODE);
                    }

                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['update_content'], $this->strategyKey);
                    $res = $registry->execute('update_content', [
                        'content_id' => $content_id,
                        'status' => $status,
                        'content' => $content,
                    ]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }

    private function getOverviewTool(): Tool
    {
        return Tool::make('get_overview', 'Returns a high-level overview of angles, sources, and content.')
            ->setCallable(function (): string {
                return $this->executeWithLogging('get_overview', [], function () {
                    $registry = new ToolRegistry();
                    $this->agentTools->register($registry, ['get_overview'], $this->strategyKey);
                    $res = $registry->execute('get_overview', ['s' => $this->strategyKey]);
                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                });
            });
    }
}
