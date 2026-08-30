<?php

use App\Http\Controllers\AgentsPageController;
use App\Http\Controllers\AnglePageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EinstellungenPageController;
use App\Http\Controllers\MediaPageController;
use App\Http\Controllers\NewsletterPageController;
use App\Http\Controllers\OutputPageController;
use App\Http\Controllers\PersonaPageController;
use App\Http\Controllers\QuellenPageController;
use App\Http\Controllers\QuickInputPageController;
use App\Http\Controllers\RedaktionsplanPageController;
use App\Http\Controllers\StrategyPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/quellen', [QuellenPageController::class, 'index'])->name('quellen');
Route::get('/personas', [PersonaPageController::class, 'index'])->name('personas');
Route::get('/output', [OutputPageController::class, 'index'])->name('output');
Route::get('/angles', [AnglePageController::class, 'index'])->name('angles');
Route::get('/angles/{angle}', [AnglePageController::class, 'show'])->name('angles.show');
Route::get('/quick-input', [QuickInputPageController::class, 'index'])->name('quick-input');
Route::get('/redaktionsplan', [RedaktionsplanPageController::class, 'index'])->name('redaktionsplan');
Route::get('/strategie', [StrategyPageController::class, 'index'])->name('strategie');
Route::get('/medien', [MediaPageController::class, 'index'])->name('medien');
Route::get('/newsletter', [NewsletterPageController::class, 'index'])->name('newsletter');
Route::get('/agents', [AgentsPageController::class, 'index'])->name('agents');
Route::get('/einstellungen', [EinstellungenPageController::class, 'index'])->name('einstellungen');
