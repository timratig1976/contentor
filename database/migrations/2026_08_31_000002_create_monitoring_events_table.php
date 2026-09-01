<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_events', function (Blueprint $table) {
            $table->id();
            // Suche / Scrape / Crawl
            $table->string('type', 32);
            // success | error | changed | unchanged | scheduled
            $table->string('status', 32)->default('success');
            $table->string('url')->nullable();
            $table->string('query')->nullable();
            // z. B. "web/search/firecrawl"
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->decimal('credits', 12, 6)->nullable();
            $table->json('input')->nullable();
            $table->json('payload')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_events');
    }
};
