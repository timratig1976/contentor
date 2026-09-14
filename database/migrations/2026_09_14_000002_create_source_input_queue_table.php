<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zentrale Eingang-Queue für automatisierte Quellen (RSS, YouTube, OCR, Community).
     *
     * Entkoppelt Quellen-Verarbeitung von der Angle-Erstellung:
     * Fetcher legt items als 'pending' an → Command extrahiert Draft-Angles →
     * Nutzer approved in der UI → /api/angles/batch (bestehender Pfad).
     */
    public function up(): void
    {
        Schema::create('source_input_queue', function (Blueprint $table) {
            $table->id();
            $table->string('source_id');
            $table->unsignedBigInteger('strategy_id');
            $table->text('raw_content');
            $table->string('item_title')->nullable();
            $table->string('item_url')->nullable();
            $table->string('item_guid')->nullable();
            $table->string('status')->default('pending'); // pending|processing|done|rejected
            $table->json('extracted_angles')->nullable(); // Draft-Angles aus LLM
            $table->string('batch_key')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'status']);
            $table->index('item_guid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_input_queue');
    }
};
