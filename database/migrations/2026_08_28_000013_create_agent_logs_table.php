<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->string('agent'); // research, angle, production, review, coordinator
            $table->string('provider');
            $table->string('model');
            $table->text('input');
            $table->text('output');
            $table->string('status')->default('success'); // success, error
            $table->integer('tokens_used')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['agent', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};