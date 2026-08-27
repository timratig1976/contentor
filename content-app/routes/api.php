<?php

use App\Http\Controllers\Api\AngleController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\RedaktionsplanController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\StrategyController;
use Illuminate\Support\Facades\Route;

// Quellen
Route::post('/sources', [SourceController::class, 'store']);
Route::get('/sources', [SourceController::class, 'index']);
Route::get('/sources/{source}/angles', [SourceController::class, 'angles']);

// Angles
Route::post('/angles', [AngleController::class, 'store']);
Route::get('/angles', [AngleController::class, 'index']);
Route::patch('/angles/{angle}', [AngleController::class, 'update']);
Route::get('/angles/batch/{batchKey}', [AngleController::class, 'batchRanking']);

// Content
Route::post('/content/idee', [ContentController::class, 'storeIdee']);
Route::post('/content/produzieren', [ContentController::class, 'produzieren']);
Route::get('/content', [ContentController::class, 'index']);
Route::patch('/content/{contentItem}', [ContentController::class, 'update']);
Route::get('/content/overview', [ContentController::class, 'overview']);

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
Route::patch('/media/{media}', [MediaController::class, 'update']);
Route::delete('/media/{media}', [MediaController::class, 'destroy']);
Route::get('/media', [MediaController::class, 'index']);

// Newsletter
Route::post('/newsletter/bk', [NewsletterController::class, 'bkDraft']);
Route::post('/newsletter/bk-newsletter', [NewsletterController::class, 'bkNewsletter']);
