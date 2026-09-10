<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AgentConfigController;
use App\Http\Controllers\Api\EdenAIModelsController;
use App\Http\Controllers\Api\AngleController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\KpiController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\PersonaController;
use App\Http\Controllers\Api\PersonaExampleController;
use App\Http\Controllers\Api\QuickInputController;
use App\Http\Controllers\Api\RedaktionsplanController;
use App\Http\Controllers\Api\StrategyCrudController;
use App\Http\Controllers\Api\StrategyPersonaController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\StrategyController;
use App\Http\Controllers\Api\WorkflowController;
use Illuminate\Support\Facades\Route;

// Quellen
Route::post('/sources', [SourceController::class, 'store']);
Route::get('/sources', [SourceController::class, 'index']);
Route::patch('/sources/{source}', [SourceController::class, 'update']);
Route::get('/sources/{source}/angles', [SourceController::class, 'angles']);
Route::post('/sources/{source}/crawl', [SourceController::class, 'crawl']);

// Angles
Route::post('/angles', [AngleController::class, 'store']);
Route::post('/angles/batch', [AngleController::class, 'storeBatch']);
Route::get('/angles', [AngleController::class, 'index']);
Route::patch('/angles/{angle}', [AngleController::class, 'update']);
Route::delete('/angles/{angle}', [AngleController::class, 'destroy']);
Route::get('/angles/batch/{batchKey}', [AngleController::class, 'batchRanking']);

// Content
Route::post('/content/idee', [ContentController::class, 'storeIdee']);
Route::post('/content/produzieren', [ContentController::class, 'produzieren']);
Route::post('/content/recommend-statement', [ContentController::class, 'recommendStatement']);
Route::get('/content', [ContentController::class, 'index']);
Route::patch('/content/{contentItem}', [ContentController::class, 'update']);
Route::post('/content/{contentItem}/select-variant', [ContentController::class, 'selectVariant']);
Route::post('/content/{contentItem}/assistant-edit', [ContentController::class, 'assistantEdit']);
Route::delete('/content/{contentItem}', [ContentController::class, 'destroy']);
Route::get('/content/overview', [ContentController::class, 'overview']);
Route::get('/content/{contentItem}/preview', [ContentController::class, 'preview']);

// KPIs + Lernschleife (Performance-Daten nach dem Publishing)
Route::post('/content-kpis', [KpiController::class, 'store']);
Route::get('/content-kpis', [KpiController::class, 'index']);
Route::get('/content-kpis/learnings', [KpiController::class, 'learnings']);

// Redaktionsplan
Route::get('/redaktionsplan', [RedaktionsplanController::class, 'index']);
Route::post('/redaktionsplan', [RedaktionsplanController::class, 'store']);

// Strategie
Route::post('/strategy', [StrategyController::class, 'store']);
Route::get('/strategy/{unit}/{key}', [StrategyController::class, 'show']);
Route::get('/strategy/{unit}', [StrategyController::class, 'index']);
Route::delete('/strategy/{unit}/{key}', [StrategyController::class, 'destroy']);

// Medien
Route::post('/media/briefing', [MediaController::class, 'storeBriefing']);
Route::post('/media/generieren', [MediaController::class, 'generieren']);
Route::post('/media/{contentItem}/brief-ideas', [MediaController::class, 'briefIdeas']);
Route::post('/media/generate-image', [MediaController::class, 'generateImage']);
Route::get('/media/gallery', [MediaController::class, 'gallery']);
Route::patch('/media/{media}', [MediaController::class, 'update']);
Route::delete('/media/{media}', [MediaController::class, 'destroy']);
Route::get('/media', [MediaController::class, 'index']);

// Newsletter
Route::post('/newsletter/bk', [NewsletterController::class, 'bkDraft']);
Route::post('/newsletter/bk-newsletter', [NewsletterController::class, 'bkNewsletter']);

// Personas
Route::get('/personas', [PersonaController::class, 'index']);
Route::post('/personas', [PersonaController::class, 'store']);
Route::patch('/personas/{persona}', [PersonaController::class, 'update']);
Route::delete('/personas/{persona}', [PersonaController::class, 'destroy']);

// Persona Few-Shot-Beispiele (Referenz-Posts)
Route::get('/personas/{persona}/examples', [PersonaExampleController::class, 'index']);
Route::post('/personas/{persona}/examples', [PersonaExampleController::class, 'store']);
Route::patch('/personas/{persona}/examples/{example}', [PersonaExampleController::class, 'update']);
Route::delete('/personas/{persona}/examples/{example}', [PersonaExampleController::class, 'destroy']);

// Settings (API Keys, Config)
Route::get('/settings', [SettingsController::class, 'index']);
Route::post('/settings', [SettingsController::class, 'store']);

// Quick Input (Text, PDF, URL → Source + Angles)
Route::post('/quick-input', [QuickInputController::class, 'store']);

// Agent Config (Prompts bearbeiten, Agenten testen)
Route::get('/agents/config', [AgentConfigController::class, 'index']);
Route::post('/agents/config', [AgentConfigController::class, 'store']);
Route::post('/agents/test', [AgentConfigController::class, 'test']);
Route::get('/agents/context', [AgentConfigController::class, 'context']);

// EdenAI Models (dynamisch laden)
Route::get('/edenai/models', [EdenAIModelsController::class, 'index']);
Route::get('/edenai/models/all', [EdenAIModelsController::class, 'all']);
Route::post('/edenai/models/curated', [EdenAIModelsController::class, 'store']);

// AI Assistant (Chat + DB Write)
Route::post('/assistant/chat', [AssistantController::class, 'chat']);
Route::post('/assistant/write', [AssistantController::class, 'write']);

// Strategies CRUD
Route::get('/strategies', [StrategyCrudController::class, 'index']);
Route::post('/strategies', [StrategyCrudController::class, 'store']);
Route::patch('/strategies/{strategy}', [StrategyCrudController::class, 'update']);
Route::delete('/strategies/{strategy}', [StrategyCrudController::class, 'destroy']);

// Strategie ↔ Persona Mapping (globale Personas)
Route::get('/strategies/{strategy}/personas', [StrategyPersonaController::class, 'index']);
Route::post('/strategies/{strategy}/personas', [StrategyPersonaController::class, 'attach']);
Route::delete('/strategies/{strategy}/personas/{persona}', [StrategyPersonaController::class, 'detach']);

// Monitoring (Quellen-Crawls, Event-Feed, Test-Suche)
Route::get('/monitoring', [MonitoringController::class, 'index']);
Route::post('/monitoring/run', [MonitoringController::class, 'run']);
Route::post('/monitoring/sources/{source}/check', [MonitoringController::class, 'checkSource']);
Route::post('/monitoring/test-search', [MonitoringController::class, 'testSearch']);

// Workflow-Runner (Python-Multi-Agent-Debug) + Verlauf
Route::post('/workflow/run', [WorkflowController::class, 'run']);
Route::get('/workflow/runs', [WorkflowController::class, 'index']);
Route::get('/workflow/runs/{run}', [WorkflowController::class, 'show']);
