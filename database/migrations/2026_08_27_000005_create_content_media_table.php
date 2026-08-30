<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_media', function (Blueprint $table) {
            $table->string('id')->primary(); // MED-xxx
            $table->string('content_item_id');
            $table->foreign('content_item_id')->references('id')->on('content_items')->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['image', 'video', 'graphic', 'carousel_slide', 'ad_creative']);
            $table->string('url')->nullable();
            $table->string('drive_file_id')->nullable();
            $table->enum('format', ['4:5', '9:16', '1:1', '16:9', '1.91:1'])->nullable();
            $table->string('status')->default('briefing'); // briefing, generiert, in_drive, live, verworfen
            $table->jsonb('briefing')->nullable(); // prompt, params, notes
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('content_item_id');
            $table->index(['strategy_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_media');
    }
};
