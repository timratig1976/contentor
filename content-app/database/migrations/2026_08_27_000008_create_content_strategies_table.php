<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // brand_voice, channel_rules, icp_channel_mapping, media_logic, editorial_rhythm, content_strategy, post_templates, content_personas
            $table->jsonb('content');
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->unique(['unit_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_strategies');
    }
};
