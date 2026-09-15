# Migration Plan: Python Agent → Pure PHP/Laravel

> **Created:** 2026-09-15  
> **Scope:** Replace `content-agent/` (Python/Haystack) with native Laravel services + queued jobs  
> **Goal:** Single runtime, single `.env`, deployable on any VPS with PHP 8.3 + Nginx + PostgreSQL  
> **Effort estimate:** ~3 focused days  
> **Feature loss:** None — all functionality maps 1:1

---

## 1. Why this is safe

The Python agent is **not doing any AI magic PHP can't do**. Here is the full picture:

| Python component | What it actually does | PHP equivalent |
|---|---|---|
| `EdenAIChatGenerator` | HTTP POST to `api.edenai.run/v3` | `LlmService::chat()` — already exists |
| `api_tools.py` (all functions) | HTTP calls to Laravel's own `/api/*` endpoints | Direct Eloquent/Service calls — no HTTP round-trip |
| `tools/web_search.py` | HTTP calls to EdenAI Firecrawl | `EdenAIWebService::search()` / `scrape()` — already exists |
| `Haystack Agent` loop | `while(hasToolCalls) { executeTool(); }` | ~30 lines of PHP in `AgentOrchestrator` |
| `coordinator.py` | Decides which sub-agent to call next | One `AgentOrchestrator` + `Bus::chain()` |
| `config.py` | Reads env vars + DB settings | Already in `LlmService::configFor()` + `Setting` model |
| `main.py` CLI | `while(true) { readline(); run(); }` | Artisan command `agent:run` |

**The Python layer exists solely because the project started there. Everything it does is now replicated in PHP.**

---

## 2. Target architecture

```
Before (hybrid):                    After (pure Laravel):
─────────────────────────────       ──────────────────────────────────────
Browser/UI                          Browser/UI
   ↓                                   ↓
Laravel (Inertia/Vue)               Laravel (Inertia/Vue)
   ↓                                   ↓
/api/* routes                       /api/* routes
   ↓                                   ↓
Laravel Services (PHP)              Laravel Services (PHP)
   ↓         ↑                         ↓
Python CLI ──┘  (HTTP round-trip)   AgentOrchestrator (PHP)
   ↓                                   ↓
EdenAI API                          EdenAI API (direct, no round-trip)
```

---

## 3. File mapping: Python → PHP

| Python file | PHP replacement | New/Exists |
|---|---|---|
| `content-agent/edenai_generator.py` | `app/Services/LlmService.php` | ✅ Exists |
| `content-agent/tools/web_search.py` | `app/Services/EdenAIWebService.php` | ✅ Exists |
| `content-agent/tools/api_tools.py` | Direct Eloquent models + existing Services | ✅ Exists |
| `content-agent/config.py` | `LlmService::configFor()` + `.env` + `Setting` | ✅ Exists |
| `content-agent/agents/research_agent.py` | `app/Services/Agents/ResearchAgent.php` | 🆕 New |
| `content-agent/agents/angle_agent.py` | `app/Services/Agents/AngleAgent.php` | 🆕 New |
| `content-agent/agents/production_agent.py` | `app/Services/Agents/ProductionAgent.php` | 🆕 New |
| `content-agent/agents/review_agent.py` | `app/Services/Agents/ReviewAgent.php` | 🆕 New |
| `content-agent/agents/coordinator.py` | `app/Services/Agents/CoordinatorAgent.php` | 🆕 New |
| `content-agent/main.py` | `app/Console/Commands/AgentRun.php` | 🆕 New |
| `content-agent/` (entire folder) | Deleted after verification | 🗑 Remove |

---

## 4. Phase breakdown

### Phase 1 — Core: `AgentOrchestrator` (Day 1, ~3h)

**File:** `app/Services/Agents/AgentOrchestrator.php`

This is the heart of the migration. It replaces Haystack's `Agent` class — the tool-call loop.

**What it does:**
1. Takes a system prompt, a user message, and a list of tool definitions
2. Calls `LlmService::chatWithTools()` (new method — see below)
3. If the LLM responds with tool calls, executes them via a `ToolRegistry`
4. Appends results as `role: tool` messages and loops
5. Returns the final text when the LLM stops calling tools

**New method needed on `LlmService`:** `chatWithTools(agent, systemPrompt, messages, tools, overrides)`  
EdenAI v3 accepts the standard OpenAI function-calling format (`tools: [{type: "function", function: {name, description, parameters}}]`).  
`LlmService` already builds the request body — this just adds the `tools` key.

**`ToolRegistry`:** A simple class that maps tool name → PHP callable.  
Each agent registers its own tools. No magic — just an associative array of closures.

---

### Phase 2 — Tool implementations (Day 1–2, ~4h)

All tools in `api_tools.py` made HTTP calls back to Laravel's own API.  
In PHP, these become **direct method calls** — no HTTP, no serialization overhead.

| Python tool | PHP replacement |
|---|---|
| `get_strategy_context(persona?)` | `AgentContextService::getContext(strategy, persona_id?)` — already exists |
| `create_source(...)` | `Source::create([...])` |
| `list_sources(...)` | `Source::where(...)->get()` |
| `create_angle(...)` | `Angle::create([...])` |
| `list_angles(...)` | `Angle::where(...)->get()` |
| `update_angle(id, ...)` | `Angle::find(id)->update([...])` |
| `get_batch_ranking(batch_key)` | `Angle::where('batch_key', ...)->orderBy('ranking_score','desc')->get()` |
| `get_strategy(strategy)` | `Strategy::where('key', strategy)->first()` |
| `produce_content(...)` | `ContentController::produzieren()` logic (already in PHP) |
| `list_content(status?)` | `ContentItem::where('status', ...)->get()` |
| `update_content(id, ...)` | `ContentItem::find(id)->update([...])` |
| `create_media_briefing(...)` | `MediaBriefingService::create(...)` — already exists |
| `web_search(query)` | `EdenAIWebService::search(query)` — already exists |
| `scrape_page(url)` | `EdenAIWebService::scrape(url)` — already exists |

**Effort: minimal** — most are 1-liners wrapping existing code.

---

### Phase 3 — Four Agent classes (Day 2, ~4h)

Each agent is one PHP class with:
- A `systemPrompt()` method returning the system prompt string (ported from `.py` files verbatim)
- A `tools()` method returning the tool definitions array (OpenAI function format)
- A `run(string $userMessage, array $context = []): string` method calling `AgentOrchestrator`

**`ResearchAgent`** — ports `research_agent.py`  
Tools: `web_search`, `scrape_page`, `get_strategy_context`, `create_source`, `list_sources`, `create_angle`, `get_batch_ranking`, `create_content_idea`

**`AngleAgent`** — ports `angle_agent.py`  
Tools: `create_angle`, `list_angles`, `update_angle`, `get_batch_ranking`, `get_strategy`

**`ProductionAgent`** — ports `production_agent.py`  
Tools: `produce_content`, `list_content`, `update_content`, `get_strategy`, `list_angles`, `get_batch_ranking`

**`ReviewAgent`** — ports `review_agent.py`  
Tools: `list_content`, `update_content`, `get_strategy`, `create_media_briefing`

**System prompts:** Copy verbatim from the `.py` files. They are plain strings — no Python-specific syntax.  
The only change: replace `f"{CONTENT_STRATEGY}"` with `{$this->strategy}` (PHP interpolation).

---

### Phase 4 — `CoordinatorAgent` + feedback loops (Day 2–3, ~3h)

Ports `coordinator.py` — the orchestrator that calls sub-agents as tools.

In Python, Haystack wraps each sub-agent as an `AgentTool`. In PHP:  
Each sub-agent (`ResearchAgent`, etc.) is registered in the `ToolRegistry` as a callable that calls `$agent->run($input)`. The Coordinator's `AgentOrchestrator` loop calls them exactly as Python does.

**Feedback loops** (Quality Loop: review → production → review):  
The coordinator system prompt already encodes the loop logic as instructions to the LLM.  
The `CoordinatorAgent::run()` method also reads `workflow_loops` from `Setting` (same source as `fetch_workflow_loops()` in Python) and injects the same loop config string into the system prompt.

---

### Phase 5 — Artisan command (Day 3, ~1h)

**File:** `app/Console/Commands/AgentRun.php`

Replaces `content-agent/main.py`.

```
php artisan agent:run
```

- Interactive REPL loop (like `main.py`)
- Accepts the same natural language inputs
- Passes them to `CoordinatorAgent::run()`
- Streams output to terminal (via `$this->line()`)

Optionally: also expose this via a Laravel Echo / Inertia page so it works in the browser — which `main.py` could never do.

---

### Phase 6 — Verification & cleanup (Day 3, ~2h)

1. **Smoke tests** — run these scenarios against the PHP agent:
   - `"Recherchiere zum Thema CRM-Datenqualität"` → sources + angles created in DB
   - `"Ranke alle Angles im Batch crm-trends-2026"` → scores updated
   - `"Produziere Content aus Top-3-Angles als Newsletter"` → content items created
   - Full pipeline: research → angle → produce → review → feedback loop triggers

2. **Delete Python**:
   ```bash
   rm -rf content-agent/
   ```

3. **Update `docker-compose.yml`** — remove the Python service if one was added.

4. **Update `.env.example`** — remove `EDENAI_API_KEY` duplicate (it's already `llm_keys` in DB),  
   remove `CONTENT_API_URL`, `CONTENT_STRATEGY` env vars (now all in DB via `Setting` model).

---

## 5. New files summary

```
app/
└── Services/
    └── Agents/
        ├── AgentOrchestrator.php     ← core tool-call loop (~80 lines)
        ├── ToolRegistry.php          ← name → callable map (~30 lines)
        ├── AgentTools.php            ← all tool definitions + implementations (~200 lines)
        ├── ResearchAgent.php         ← system prompt + tool list + run() (~80 lines)
        ├── AngleAgent.php            ← system prompt + tool list + run() (~60 lines)
        ├── ProductionAgent.php       ← system prompt + tool list + run() (~80 lines)
        ├── ReviewAgent.php           ← system prompt + tool list + run() (~70 lines)
        └── CoordinatorAgent.php      ← orchestrator + loop config (~100 lines)

app/
└── Console/
    └── Commands/
        └── AgentRun.php              ← artisan agent:run (~60 lines)
```

**Total new code: ~760 lines of PHP.**  
**Deleted: ~800 lines of Python** (across 10 files + requirements.txt).

---

## 6. Changes to existing files

| File | Change |
|---|---|
| `app/Services/LlmService.php` | Add `chatWithTools()` method (~40 lines) |
| `routes/console.php` | Register `AgentRun` command |
| `docker-compose.yml` | Remove Python service (if present) |
| `.env.example` | Remove Python-only env vars |
| `content-agent/` | Delete entirely after Phase 6 |

---

## 7. Risk assessment

| Risk | Likelihood | Mitigation |
|---|---|---|
| EdenAI tool-calling format differs from OpenAI | Low — EdenAI v3 is OpenAI-compatible, `edenai_generator.py` confirms this | Test with one agent first before porting all four |
| System prompt behaviour changes between Haystack and raw API | Very low — same model, same prompt, same API | Run parallel (Python + PHP) for one day before deleting Python |
| Loop termination (agent calls tools forever) | Low — Haystack has this same risk | Add `$maxIterations = 15` guard in `AgentOrchestrator` |
| Streaming output loss in Artisan command | None for function; cosmetic for CLI | Use `$this->line()` per chunk; browser UI is better anyway |

---

## 8. Deployment after migration

Once Python is gone, a full production deploy is:

```bash
# On any VPS with PHP 8.3 + PostgreSQL + Nginx
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build          # or pre-built in CI
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

**No Docker required** (though Docker still works fine if preferred).  
**No Python runtime.** **No pip.** **No virtualenv.**  
One language. One `composer install`. Done.

---

## 9. Execution order

```
Day 1 AM  — Phase 1: LlmService::chatWithTools() + AgentOrchestrator + ToolRegistry
Day 1 PM  — Phase 2: AgentTools.php (all tool implementations)
Day 2 AM  — Phase 3: ResearchAgent + AngleAgent
Day 2 PM  — Phase 3: ProductionAgent + ReviewAgent
Day 3 AM  — Phase 4: CoordinatorAgent (+ feedback loop config)
Day 3 PM  — Phase 5: AgentRun Artisan command
           — Phase 6: Smoke tests → delete content-agent/ → cleanup
```

---

*This plan can be executed as written. Start with `php artisan agent:run` working end-to-end for a single `ResearchAgent` call before building the full coordinator — that validates the core loop early.*
