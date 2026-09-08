<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->string('input', 500)->nullable();        // User-Input / Aufgabe
            $table->text('output')->nullable();              // Konsolen-Trace (readable)
            $table->text('trace')->nullable();               // maschinenlesbare Trace (JSON)
            $table->string('status')->default('running');    // running, success, error
            $table->integer('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};