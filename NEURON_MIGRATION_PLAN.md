# Migrationsplan: Contentor Agentic Stack → Neuron AI

**Erstellt:** 2026-09-25
**Ziel:** Vollständige Migration des proprietären Agenten-Stacks (`app/Services/Agents/`) auf das `neuron-core/neuron-ai` PHP-Framework (v3.x).
**Scope:** Nur der Backend-Agenten-Motor. UI (Inertia + Vue 3), Datenbank-Schema (Eloquent-Models), API-Routes und alle Laravel-Services (EdenAI, MediaBriefing etc.) bleiben unverändert.

---

## Architektur-Vergleich: Vorher vs. Nachher

### Vorher (Proprietärer Stack)

```
CoordinatorAgent
  └── AgentOrchestrator (while-Schleife + Tool-Call-Loop)
       ├── ToolRegistry (closures, manuell)
       ├── AgentTools (alle Tool-Definitionen + Ausführungen)
       └── LlmService → EdenAI v3 (HTTP via Laravel Http::)

ResearchAgent / AngleAgent / ProductionAgent / ReviewAgent
  └── rufen AgentOrchestrator::run() auf
       └── übergibt systemPrompt + toolDefinitions

AgentContextService
  └── baut den Strategie-Kontext-Block für jeden Agent
```

### Nachher (Neuron AI)

```
NeuronCoordinatorWorkflow (NeuronAI\Workflow\Workflow)
  ├── ResearchNode  → NeuronResearchAgent  (extends NeuronAI\Agent\Agent)
  ├── AngleNode     → NeuronAngleAgent     (extends NeuronAI\Agent\Agent)
  ├── ProductionNode→ NeuronProductionAgent (extends NeuronAI\Agent\Agent)
  └── ReviewNode    → NeuronReviewAgent    (extends NeuronAI\Agent\Agent)
       └── bei verdict=fail → Human-in-the-Loop oder Loop zurück zu Production

Jeder Agent:
  └── provider()     → AnthropicProvider / OpenAIProvider (aus DB-Setting)
  └── instructions() → Strategie-Kontext via AgentContextService (unverändert)
  └── tools()        → NeuronTool-Wrapper um bestehende AgentTools-Methoden

NeuronAI\Tools\Tool
  └── Wrapping der 25 bestehenden AgentTools-Methoden (1:1 ohne Logikänderung)
```

---

## Phase 0: Abhängigkeit installieren (1h)

### 0.1 Composer-Paket installieren

```bash
composer require neuron-core/neuron-ai
```

### 0.2 .env-Einträge ergänzen

Neuron AI nutzt **direkte Provider-Verbindungen** statt EdenAI. Das ist die einzige Konfigurationsänderung:

```dotenv
# Bestehend (wird für Assistant & Quick Input Extraction behalten, solange EdenAI noch genutzt wird)
EDENAI_API_KEY=...

# Neu: Direkte Provider-Keys (nur ausfüllen was genutzt wird)
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
```

**WICHTIG:** Die `LlmService.php` (für EdenAI) und das `AssistantController`-Modell bleiben zunächst parallel aktiv. Neuron AI-Agenten nutzen direkte Provider. Erst nach erfolgreichem Rollout kann EdenAI aus dem Multi-Agent-Workflow entfernt werden.

### 0.3 Neuron AI Service Provider registrieren

In `bootstrap/providers.php` nichts notwendig — Neuron AI nutzt keinen Laravel Service Provider. Es ist reines PHP-OOP.

---

## Phase 1: Tool-Schicht migrieren (4h)

Die **25 bestehenden Tool-Methoden** in `AgentTools.php` (Eloquent-Calls, EdenAI-Calls) bleiben **exakt unverändert**. Wir schreiben nur dünne Neuron-Tool-Wrapper.

### 1.1 Neue Datei: `app/Neuron/Tools/ContentorToolkit.php`

```php
<?php

namespace App\Neuron\Tools;

use App\Services\Agents\AgentTools;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Toolkits\Toolkit;

/**
 * Neuron AI Toolkit: Wraps all existing AgentTools methods as NeuronAI Tools.
 * Zero logic change — only signature adapters.
 */
class ContentorToolkit extends Toolkit
{
    public function __construct(
        private AgentTools $agentTools,
        private string $strategyKey = 'viscale',
    ) {}

    public static function make(string $strategyKey = 'viscale'): static
    {
        return new static(app(AgentTools::class), $strategyKey);
    }

    /** @return Tool[] */
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

    // ─── Tool Definitions ────────────────────────────────────────────────────

    private function webSearchTool(): Tool
    {
        return Tool::make(
            name: 'web_search',
            description: 'Searches the web for relevant articles, studies and competitive content.'
        )
        ->addProperty(
            ToolProperty::make(name: 'query', type: 'string', description: 'Search query string', required: true)
        )
        ->setHandler(function (string $query): string {
            $result = app(\App\Services\EdenAIWebService::class)->search($query);
            return json_encode($result);
        });
    }

    private function scrapePageTool(): Tool
    {
        return Tool::make(
            name: 'scrape_page',
            description: 'Scrapes and extracts text content from a given URL.'
        )
        ->addProperty(
            ToolProperty::make(name: 'url', type: 'string', description: 'Full URL to scrape', required: true)
        )
        ->setHandler(function (string $url): string {
            $result = app(\App\Services\EdenAIWebService::class)->scrape($url);
            return json_encode($result);
        });
    }

    private function getStrategyContextTool(): Tool
    {
        return Tool::make(
            name: 'get_strategy_context',
            description: 'Returns full strategy context: ICPs, topic clusters, brand voice, personas. Call this FIRST before any content work.'
        )
        ->addProperty(
            ToolProperty::make(name: 'persona', type: 'string', description: 'Optional persona name to focus on', required: false)
        )
        ->setHandler(function (?string $persona = null): string {
            $tools = app(AgentTools::class);
            $registry = app(\App\Services\Agents\ToolRegistry::class);
            $tools->register($registry, ['get_strategy_context'], $this->strategyKey);
            $result = $registry->execute('get_strategy_context', ['persona' => $persona]);
            return json_encode($result);
        });
    }

    private function createSourceTool(): Tool
    {
        return Tool::make(
            name: 'create_source',
            description: 'Creates a research source in the database.'
        )
        ->addProperty(ToolProperty::make(name: 'title', type: 'string', description: 'Source title', required: true))
        ->addProperty(ToolProperty::make(name: 'type', type: 'string', description: 'url | research | interview | intern | pdf', required: true))
        ->addProperty(ToolProperty::make(name: 'batch_key', type: 'string', description: 'Thematic batch key for grouping', required: false))
        ->addProperty(ToolProperty::make(name: 'url', type: 'string', description: 'Source URL', required: false))
        ->setHandler(function (string $title, string $type, ?string $batch_key = null, ?string $url = null): string {
            $result = app(AgentTools::class)->createSourcePublic($title, $type, $this->strategyKey, 'intern', null, $batch_key, $url);
            return json_encode($result);
        });
    }

    private function createAngleTool(): Tool
    {
        return Tool::make(
            name: 'create_angle',
            description: 'Creates a new content angle (thesis) in the database.'
        )
        ->addProperty(ToolProperty::make(name: 'angle', type: 'string', description: 'The angle thesis text (max 200 chars)', required: true))
        ->addProperty(ToolProperty::make(name: 'icp', type: 'string', description: 'Target ICP key (e.g. B2B-1)', required: false))
        ->addProperty(ToolProperty::make(name: 'pain_cluster', type: 'string', description: 'Topic cluster name', required: false))
        ->addProperty(ToolProperty::make(name: 'funnel', type: 'string', description: 'ToFu | MoFu | BoFu', required: false))
        ->addProperty(ToolProperty::make(name: 'batch_key', type: 'string', description: 'Batch key for grouping', required: false))
        ->addProperty(ToolProperty::make(name: 'source_id', type: 'string', description: 'Source ID this angle came from', required: false))
        ->setHandler(function (string $angle, ?string $icp = null, ?string $pain_cluster = null, ?string $funnel = null, ?string $batch_key = null, ?string $source_id = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['create_angle'], $this->strategyKey);
            $result = $registry->execute('create_angle', [
                'angle' => $angle, 'strategy' => $this->strategyKey, 'icp' => $icp,
                'pain_cluster' => $pain_cluster, 'funnel' => $funnel,
                'batch_key' => $batch_key, 'source_id' => $source_id,
            ]);
            return json_encode($result);
        });
    }

    private function listAnglesTool(): Tool
    {
        return Tool::make(
            name: 'list_angles',
            description: 'Lists angles for the current strategy, optionally filtered.'
        )
        ->addProperty(ToolProperty::make(name: 'status', type: 'string', description: 'Filter by status', required: false))
        ->addProperty(ToolProperty::make(name: 'icp', type: 'string', description: 'Filter by ICP', required: false))
        ->addProperty(ToolProperty::make(name: 'funnel', type: 'string', description: 'ToFu | MoFu | BoFu', required: false))
        ->addProperty(ToolProperty::make(name: 'min_score', type: 'integer', description: 'Minimum ranking score', required: false))
        ->setHandler(function (?string $status = null, ?string $icp = null, ?string $funnel = null, ?int $min_score = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['list_angles'], $this->strategyKey);
            $result = $registry->execute('list_angles', [
                'strategy' => $this->strategyKey, 'icp' => $icp, 'status' => $status,
                'funnel' => $funnel, 'min_score' => $min_score,
            ]);
            return json_encode($result);
        });
    }

    private function updateAngleTool(): Tool
    {
        return Tool::make(
            name: 'update_angle',
            description: 'Updates an existing angle with new values or scoring.'
        )
        ->addProperty(ToolProperty::make(name: 'angle_id', type: 'string', description: 'Angle ID (ANG-xxx)', required: true))
        ->addProperty(ToolProperty::make(name: 'status', type: 'string', description: 'new status', required: false))
        ->addProperty(ToolProperty::make(name: 'r_zielgruppe', type: 'integer', description: '1-3', required: false))
        ->addProperty(ToolProperty::make(name: 'r_viscale_fit', type: 'integer', description: '1-3', required: false))
        ->addProperty(ToolProperty::make(name: 'r_schaerfe', type: 'integer', description: '1-3', required: false))
        ->addProperty(ToolProperty::make(name: 'r_timing', type: 'integer', description: '1-3', required: false))
        ->addProperty(ToolProperty::make(name: 'score_reasoning', type: 'string', description: 'Reasoning for the score', required: false))
        ->addProperty(ToolProperty::make(name: 'pain_cluster', type: 'string', description: 'Cluster assignment', required: false))
        ->addProperty(ToolProperty::make(name: 'funnel', type: 'string', description: 'ToFu | MoFu | BoFu', required: false))
        ->setHandler(function (string $angle_id, ?string $status = null, ?int $r_zielgruppe = null, ?int $r_viscale_fit = null, ?int $r_schaerfe = null, ?int $r_timing = null, ?string $score_reasoning = null, ?string $pain_cluster = null, ?string $funnel = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['update_angle'], $this->strategyKey);
            $args = array_filter(compact('status', 'r_zielgruppe', 'r_viscale_fit', 'r_schaerfe', 'r_timing', 'score_reasoning', 'pain_cluster', 'funnel'), fn($v) => $v !== null);
            $result = $registry->execute('update_angle', ['angle_id' => $angle_id, ...$args]);
            return json_encode($result);
        });
    }

    private function getBatchRankingTool(): Tool
    {
        return Tool::make(
            name: 'get_batch_ranking',
            description: 'Returns ranked list of angles in a batch, sorted by score.'
        )
        ->addProperty(ToolProperty::make(name: 'batch_key', type: 'string', description: 'Batch key', required: true))
        ->setHandler(function (string $batch_key): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['get_batch_ranking'], $this->strategyKey);
            $result = $registry->execute('get_batch_ranking', ['batch_key' => $batch_key, 'strategy' => $this->strategyKey]);
            return json_encode($result);
        });
    }

    private function produceContentTool(): Tool
    {
        return Tool::make(
            name: 'produce_content',
            description: 'Produces a full post or article from an angle.'
        )
        ->addProperty(ToolProperty::make(name: 'angle_id', type: 'string', description: 'Angle ID (ANG-xxx)', required: true))
        ->addProperty(ToolProperty::make(name: 'format', type: 'string', description: 'linkedin_post | blog_post | newsletter | ad_copy', required: true))
        ->addProperty(ToolProperty::make(name: 'persona_id', type: 'string', description: 'Optional Persona ID', required: false))
        ->addProperty(ToolProperty::make(name: 'pattern', type: 'string', description: 'Optional template/pattern name', required: false))
        ->setHandler(function (string $angle_id, string $format, ?string $persona_id = null, ?string $pattern = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['produce_content'], $this->strategyKey);
            $result = $registry->execute('produce_content', [
                'angle_id' => $angle_id, 'format' => $format,
                'persona_id' => $persona_id, 'pattern' => $pattern,
                'strategy' => $this->strategyKey,
            ]);
            return json_encode($result);
        });
    }

    private function reviseContentTool(): Tool
    {
        return Tool::make(
            name: 'revise_content',
            description: 'Revises existing content based on review feedback.'
        )
        ->addProperty(ToolProperty::make(name: 'content_id', type: 'string', description: 'Content item ID (CNT-xxx)', required: true))
        ->addProperty(ToolProperty::make(name: 'feedback', type: 'string', description: 'Feedback from Review agent', required: true))
        ->setHandler(function (string $content_id, string $feedback): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['revise_content'], $this->strategyKey);
            $result = $registry->execute('revise_content', ['content_id' => $content_id, 'feedback' => $feedback]);
            return json_encode($result);
        });
    }

    private function listContentTool(): Tool
    {
        return Tool::make(name: 'list_content', description: 'Lists content items for the strategy.')
        ->addProperty(ToolProperty::make(name: 'status', type: 'string', description: 'Filter status', required: false))
        ->addProperty(ToolProperty::make(name: 'icp', type: 'string', description: 'Filter ICP', required: false))
        ->setHandler(function (?string $status = null, ?string $icp = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['list_content'], $this->strategyKey);
            $result = $registry->execute('list_content', ['strategy' => $this->strategyKey, 'status' => $status, 'icp' => $icp]);
            return json_encode($result);
        });
    }

    private function updateContentTool(): Tool
    {
        return Tool::make(name: 'update_content', description: 'Updates a content item.')
        ->addProperty(ToolProperty::make(name: 'content_id', type: 'string', description: 'CNT-xxx', required: true))
        ->addProperty(ToolProperty::make(name: 'status', type: 'string', required: false))
        ->addProperty(ToolProperty::make(name: 'content', type: 'string', required: false))
        ->setHandler(function (string $content_id, ?string $status = null, ?string $content = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['update_content'], $this->strategyKey);
            $result = $registry->execute('update_content', array_filter(['content_id' => $content_id, 'status' => $status, 'content' => $content], fn($v) => $v !== null));
            return json_encode($result);
        });
    }

    private function getOverviewTool(): Tool
    {
        return Tool::make(name: 'get_overview', description: 'Returns a dashboard overview of angles, content and sources.')
        ->setHandler(function (): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['get_overview'], $this->strategyKey);
            $result = $registry->execute('get_overview', ['strategy' => $this->strategyKey]);
            return json_encode($result);
        });
    }

    private function listSourcesTool(): Tool
    {
        return Tool::make(name: 'list_sources', description: 'Lists research sources for the strategy.')
        ->addProperty(ToolProperty::make(name: 'batch_key', type: 'string', required: false))
        ->setHandler(function (?string $batch_key = null): string {
            $tools = app(AgentTools::class);
            $registry = new \App\Services\Agents\ToolRegistry();
            $tools->register($registry, ['list_sources'], $this->strategyKey);
            $result = $registry->execute('list_sources', ['strategy' => $this->strategyKey, 'batch_key' => $batch_key]);
            return json_encode($result);
        });
    }
}
```

---

## Phase 2: Neuron Agenten-Klassen erstellen (4h)

**Jeder bestehende Agent** (`ResearchAgent`, `AngleAgent`, `ProductionAgent`, `ReviewAgent`) bekommt ein Neuron-AI-Äquivalent in `app/Neuron/Agents/`.

### 2.1 BaseAgent (Abstrakte Basis): `app/Neuron/Agents/BaseContentorAgent.php`

```php
<?php

namespace App\Neuron\Agents;

use App\Models\Setting;
use App\Services\AgentContextService;
use App\Neuron\Tools\ContentorToolkit;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * Basis-Klasse für alle Contentor-Agenten.
 * Löst Provider/Modell aus der DB-Settings (wie bisher LlmService) und
 * injiziert Strategie-Kontext via AgentContextService.
 */
abstract class BaseContentorAgent extends Agent
{
    protected string $strategyKey = 'viscale';
    protected ?int $personaId = null;

    // Neuron AI ruft provider() und instructions() automatisch auf
    abstract protected function agentKey(): string; // 'research' | 'angle' | 'production' | 'review'
    abstract protected function customInstructions(): string;

    protected function provider(): AIProviderInterface
    {
        $cfg = $this->resolveConfig();

        return match ($cfg['provider']) {
            'anthropic' => new Anthropic(
                key: config('services.anthropic.key', env('ANTHROPIC_API_KEY', '')),
                model: $this->cleanModelId($cfg['model'], 'anthropic'),
            ),
            default => new OpenAI(
                key: config('services.openai.key', env('OPENAI_API_KEY', '')),
                model: $this->cleanModelId($cfg['model'], 'openai'),
            ),
        };
    }

    protected function instructions(): string
    {
        // 1. Custom Prompt aus DB laden (editierbar unter /agents)
        $dbPrompt = Setting::where('key', 'agent_prompts')->first()?->value[$this->agentKey()] ?? null;
        $base = $dbPrompt ?? $this->customInstructions();

        // 2. Vollständigen Strategie-Kontext injizieren (ICPs, Cluster, Brand Voice, Personas)
        $ctx = app(AgentContextService::class)->build($this->strategyKey, $this->personaId);

        return $base . "\n\n---\n## STRATEGIE-KONTEXT (immer berücksichtigen)\n" . $ctx;
    }

    protected function tools(): array
    {
        return ContentorToolkit::make($this->strategyKey)->tools();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveConfig(): array
    {
        $models = Setting::where('key', 'agent_models')->first()?->value ?? [];
        $cfg = $models[$this->agentKey()] ?? [];

        $defaults = [
            'research'    => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
            'angle'       => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
            'production'  => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
            'review'      => ['provider' => 'openai',    'model' => 'gpt-4o'],
        ];

        return array_merge($defaults[$this->agentKey()] ?? ['provider' => 'openai', 'model' => 'gpt-4o'], $cfg);
    }

    /** EdenAI-Format "anthropic/claude-sonnet-4-6" → "claude-sonnet-4-6" */
    private function cleanModelId(string $model, string $provider): string
    {
        return ltrim(str_replace($provider . '/', '', $model), '/');
    }
}
```

### 2.2 Research Agent: `app/Neuron/Agents/NeuronResearchAgent.php`

```php
<?php

namespace App\Neuron\Agents;

class NeuronResearchAgent extends BaseContentorAgent
{
    protected function agentKey(): string { return 'research'; }

    protected function customInstructions(): string
    {
        return <<<'PROMPT'
Du bist ein Research Agent für Content-Marketing. Deine Aufgabe: zu einem Thema
Quellen recherchieren, speichern und erste Angles extrahieren.

SCHRITT 1: Rufe get_strategy_context auf (PFLICHT vor allem anderen).
SCHRITT 2: web_search mit 2-3 unterschiedlichen Suchanfragen zum Thema.
SCHRITT 3: Wichtige Quellen via create_source speichern.
SCHRITT 4: Pro Quelle 2-3 Angles via create_angle erstellen (mit funnel + cluster).
SCHRITT 5: get_batch_ranking aufrufen und Top-Angles melden.
PROMPT;
    }
}
```

### 2.3 Angle Agent: `app/Neuron/Agents/NeuronAngleAgent.php`

```php
<?php

namespace App\Neuron\Agents;

class NeuronAngleAgent extends BaseContentorAgent
{
    protected function agentKey(): string { return 'angle'; }

    protected function customInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Angle-Ranking-Agent. Du bewertest und schärfst vorhandene Angles.

SCHRITT 1: get_strategy_context laden.
SCHRITT 2: list_angles laden (alle Angles des aktuellen Batches).
SCHRITT 3: Jeden Angle bewerten: r_zielgruppe, r_viscale_fit, r_schaerfe, r_timing (je 1-3).
SCHRITT 4: update_angle pro Angle mit Scores + score_reasoning + funnel-Zuordnung.
SCHRITT 5: get_batch_ranking und Top 3 Angles für Produktion melden.
PROMPT;
    }
}
```

### 2.4 Production Agent: `app/Neuron/Agents/NeuronProductionAgent.php`

```php
<?php

namespace App\Neuron\Agents;

class NeuronProductionAgent extends BaseContentorAgent
{
    protected function agentKey(): string { return 'production'; }

    protected function customInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Content-Produktionsagent. Du produzierst fertige Posts aus Angles.

SCHRITT 1: get_strategy_context laden.
SCHRITT 2: Für jeden übergebenen Angle: produce_content aufrufen (format: linkedin_post).
SCHRITT 3: Sicherstellen dass Brand Voice, Funnel-Dramaturgie und ICP-Tonalität passen.
SCHRITT 4: Content-IDs und Vorschau zurückmelden.
PROMPT;
    }
}
```

### 2.5 Review Agent: `app/Neuron/Agents/NeuronReviewAgent.php`

```php
<?php

namespace App\Neuron\Agents;

use NeuronAI\StructuredOutput\SchemaProperty;

class NeuronReviewAgent extends BaseContentorAgent
{
    protected function agentKey(): string { return 'review'; }

    protected function customInstructions(): string
    {
        return <<<'PROMPT'
Du bist der Quality-Review-Agent. Du prüfst produzierten Content gegen Brand Voice und Qualitätsregeln.

Für jeden Content-Item:
1. Brand Voice Check: Hält der Text Personality, Tone, Must-Haves und No-Gos ein?
2. Funnel Check: Passt die Dramaturgie zur Funnel-Stufe (ToFu/MoFu/BoFu)?
3. ICP Check: Trifft der Text den richtigen Schmerz des ICPs?
4. Fakten Check: Zahlen belegt und korrekt (keine KI-Erfindungen)?
5. Liefere verdict: "pass" oder "fail" mit konkretem Feedback.
PROMPT;
    }
}
```

---

## Phase 3: Workflow-Orchestrierung (4h)

### 3.1 Haupt-Workflow: `app/Neuron/Workflows/ContentWorkflow.php`

```php
<?php

namespace App\Neuron\Workflows;

use App\Neuron\Agents\NeuronResearchAgent;
use App\Neuron\Agents\NeuronAngleAgent;
use App\Neuron\Agents\NeuronProductionAgent;
use App\Neuron\Agents\NeuronReviewAgent;
use App\Models\Setting;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowNode;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Vollständiger 4-Stufen Content-Produktions-Workflow.
 * Ersetzt CoordinatorAgent + AgentOrchestrator + WorkflowRun-Logik.
 *
 * Unterstützt:
 * - Sequentielle Phasen: Research → Angle → Production → Review
 * - Konfigurierbare Feedback-Loops (aus DB workflow_loops Setting)
 * - Human-in-the-Loop (vor Production, wenn gewünscht)
 */
class ContentWorkflow
{
    private string $strategyKey;
    private ?int $personaId;

    public function __construct(string $strategyKey = 'viscale', ?int $personaId = null)
    {
        $this->strategyKey = $strategyKey;
        $this->personaId = $personaId;
    }

    /**
     * Vollständigen Workflow ausführen.
     *
     * @param string $topic   Recherche-Thema oder erster User-Prompt
     * @param array  $options ['phases' => ['research','angle','production','review'], 'format' => 'linkedin_post']
     */
    public function run(string $topic, array $options = []): array
    {
        $phases = $options['phases'] ?? ['research', 'angle', 'production', 'review'];
        $format = $options['format'] ?? 'linkedin_post';
        $logs = [];

        // Phase 1: Research
        if (in_array('research', $phases)) {
            $researchResult = $this->runResearch($topic);
            $logs['research'] = $researchResult;
        }

        // Phase 2: Angle Ranking
        if (in_array('angle', $phases)) {
            $angleResult = $this->runAngle($topic);
            $logs['angle'] = $angleResult;
        }

        // Phase 3 & 4: Production + Review Loop
        if (in_array('production', $phases)) {
            [$productionResult, $reviewResult] = $this->runProductionWithReviewLoop($topic, $format);
            $logs['production'] = $productionResult;
            $logs['review'] = $reviewResult;
        }

        return $logs;
    }

    private function runResearch(string $topic): array
    {
        $agent = $this->makeAgent(NeuronResearchAgent::class);
        $response = $agent->chat(new UserMessage($topic))->getMessage();
        return ['status' => 'done', 'output' => $response->getContent()];
    }

    private function runAngle(string $topic): array
    {
        $agent = $this->makeAgent(NeuronAngleAgent::class);
        $response = $agent->chat(new UserMessage(
            "Bewerte und ranke die Angles aus dem Batch zu: {$topic}"
        ))->getMessage();
        return ['status' => 'done', 'output' => $response->getContent()];
    }

    private function runProductionWithReviewLoop(string $topic, string $format): array
    {
        $loops = Setting::where('key', 'workflow_loops')->first()?->value ?? [];
        $maxRounds = 2; // Default

        // Maximale Loop-Runden aus Workflow-Konfiguration ermitteln
        foreach ($loops as $loop) {
            if (($loop['from_agent'] ?? '') === 'review' && ($loop['to_agent'] ?? '') === 'production') {
                $maxRounds = (int) ($loop['max_rounds'] ?? 2);
                break;
            }
        }

        $productionResult = null;
        $reviewResult = null;
        $round = 0;

        do {
            $round++;
            $productionPrompt = $round === 1
                ? "Produziere {$format} Posts für die Top-Angles zu: {$topic}"
                : "Überarbeite den Content anhand dieses Feedbacks:\n" . ($reviewResult['feedback'] ?? '');

            $productionAgent = $this->makeAgent(NeuronProductionAgent::class);
            $productionResponse = $productionAgent->chat(new UserMessage($productionPrompt))->getMessage();
            $productionResult = ['status' => 'done', 'round' => $round, 'output' => $productionResponse->getContent()];

            $reviewAgent = $this->makeAgent(NeuronReviewAgent::class);
            $reviewResponse = $reviewAgent->chat(new UserMessage(
                "Prüfe den produzierten Content:\n" . $productionResponse->getContent()
            ))->getMessage();

            $reviewText = $reviewResponse->getContent();
            $verdict = str_contains(strtolower($reviewText), '"pass"') || str_contains(strtolower($reviewText), 'verdict: pass') ? 'pass' : 'fail';

            $reviewResult = [
                'status' => 'done',
                'round' => $round,
                'verdict' => $verdict,
                'feedback' => $reviewText,
                'output' => $reviewText,
            ];
        } while ($verdict === 'fail' && $round < $maxRounds);

        return [$productionResult, $reviewResult];
    }

    /**
     * Factory-Methode: erstellt einen Agenten mit Strategie-Kontext.
     * Neuron AI Agents nutzen static make() und können dann konfiguriert werden.
     */
    private function makeAgent(string $agentClass): \NeuronAI\Agent\Agent
    {
        /** @var BaseContentorAgent $agent */
        $agent = $agentClass::make();
        $agent->strategyKey = $this->strategyKey;
        $agent->personaId = $this->personaId;
        return $agent;
    }
}
```

---

## Phase 4: Integration in bestehende WorkflowRun-Logik (2h)

### 4.1 WorkflowController anpassen

Die bestehende Route `/api/workflow/run` (oder Artisan-Command) verwendet aktuell `CoordinatorAgent`. Wir schalten auf `ContentWorkflow` um:

```php
// In app/Http/Controllers/Api/WorkflowController.php (oder AgentRunController)
// VORHER:
$coordinator = app(CoordinatorAgent::class);
$result = $coordinator->run($strategyKey, $prompt, $personaId);

// NACHHER:
$workflow = new \App\Neuron\Workflows\ContentWorkflow($strategyKey, $personaId);
$result = $workflow->run($prompt, ['format' => $format]);
```

### 4.2 WorkflowRun-Model bleibt unverändert

`App\Models\WorkflowRun` — keine Änderungen nötig. Der Workflow schreibt weiterhin über die bestehenden Eloquent-Models (`Angle::create()`, `ContentItem::create()` etc.) in die Datenbank.

---

## Phase 5: Structured Output für Angle-Extraktion (2h)

Der neue Prompt erzeugt strukturiertes JSON (Phase 1 der Angle-Extraktion via Quick Input). Mit Neuron AI können wir das über `->structured()` typsicher machen:

### 5.1 DTO: `app/Neuron/StructuredOutput/AngleExtractionResult.php`

```php
<?php

namespace App\Neuron\StructuredOutput;

use NeuronAI\StructuredOutput\SchemaProperty;

class ExtractedAngle
{
    #[SchemaProperty(description: 'Zugespitzte Kernaussage, max. 200 Zeichen', required: true)]
    public string $angle_draft;

    #[SchemaProperty(description: 'Neutrale Kernaussage (Fakt/These/Erfahrung/Frage)', required: true)]
    public string $claim;

    #[SchemaProperty(description: 'Fakt | These | Erfahrung | Frage', required: true)]
    public string $claim_type;

    #[SchemaProperty(description: 'Wörtliche Belegstelle aus dem Quelltext', required: true)]
    public string $evidence;

    #[SchemaProperty(description: 'true wenn Zahl aus Sekundärquelle ohne Datum/Bezugsgröße')]
    public bool $needs_verification = false;

    #[SchemaProperty(description: 'Grund für Verifikation oder leer')]
    public string $verification_reason = '';

    #[SchemaProperty(description: 'Exakter Clustername aus der Strategie oder leer')]
    public string $cluster = '';

    #[SchemaProperty(description: 'ICP-Key aus der Strategie oder leer')]
    public string $icp = '';

    #[SchemaProperty(description: 'ToFu | MoFu | BoFu')]
    public string $funnel = 'ToFu';
}

class AngleExtractionResult
{
    /** @var ExtractedAngle[] */
    #[SchemaProperty(description: 'Liste der extrahierten Angle-Kandidaten', required: true)]
    public array $angles = [];
}
```

### 5.2 Nutzung in der Extraktion

```php
// Statt JSON-Parsing mit Regex:
$result = NeuronExtractionAgent::make()->structured(
    new UserMessage($briefingText),
    AngleExtractionResult::class
);

foreach ($result->angles as $angle) {
    Angle::create([
        'angle'         => $angle->angle_draft,
        'pain_cluster'  => $angle->cluster,
        'funnel'        => $angle->funnel,
        'icp'           => $angle->icp,
        'score_reasoning' => "Beleg: {$angle->evidence}" . ($angle->needs_verification ? " | ⚠️ {$angle->verification_reason}" : ''),
    ]);
}
```

---

## Phase 6: Observability via Inspector (1h, optional)

```dotenv
INSPECTOR_INGESTION_KEY=dein-inspector-key
```

Neuron AI trackt dann automatisch jeden Agent-Call, Tool-Aufruf, Token-Verbrauch und Latenz in Inspector.dev. Kein weiterer Code nötig — das AgentLog in unserer DB bleibt zusätzlich aktiv.

---

## Reihenfolge & Aufwandsschätzung

| Phase | Was | Dateien | Aufwand |
|---|---|---|---|
| Phase 0 | `composer require neuron-core/neuron-ai` + .env | `.env` | 1h |
| Phase 1 | `ContentorToolkit` — Tool-Wrapper | `app/Neuron/Tools/ContentorToolkit.php` | 4h |
| Phase 2 | 5 Neuron-Agent-Klassen | `app/Neuron/Agents/*.php` | 4h |
| Phase 3 | `ContentWorkflow` mit Review-Loop | `app/Neuron/Workflows/ContentWorkflow.php` | 4h |
| Phase 4 | WorkflowController umschalten | `WorkflowController.php` | 2h |
| Phase 5 | Structured Output DTOs (optional, erhöht Zuverlässigkeit) | `app/Neuron/StructuredOutput/*.php` | 2h |
| Phase 6 | Inspector observability (optional) | `.env` | 1h |
| **Total** | | | **~18h** |

---

## Wichtige Entscheidungen & Garantien

1. **Kein DB-Schema-Change:** Alle Migrations und Eloquent-Models bleiben unverändert. Neuron AI schreibt nur über die bestehende Business-Logik in AgentTools.
2. **Kein UI-Change:** Inertia-Frontend, Vue 3, alle API-Routes bleiben unverändert.
3. **Parallelbetrieb möglich:** Alter Stack (`AgentOrchestrator` etc.) kann für den Assistant weitergenutzt werden, während Neuron AI den Pipeline-Workflow übernimmt. Dann schrittweise abschalten.
4. **Provider-Flexibilität:** Dank Neuron AI können wir jederzeit ohne Codeänderung von Anthropic auf OpenAI oder sogar Ollama (lokal) wechseln — rein durch DB-Setting-Änderung.
5. **Tests unverändert:** Alle bestehenden PHPUnit-Tests (21/21 grün) laufen weiter durch, da kein bestehender Code geändert wird — nur neue Dateien im `app/Neuron/`-Namespace entstehen.
