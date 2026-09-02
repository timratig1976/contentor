<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            // z.B. ["revolutionär", "Game-Changer", "disruptiv"]
            $table->jsonb('forbidden_words')->nullable()->after('content_attributes');

            // Maximale Wörter pro Satz (z.B. 20)
            $table->unsignedTinyInteger('max_sentence_length')->nullable()->after('forbidden_words');

            // none = kein Emoji, light = 1-2 pro Post, heavy = frei
            $table->enum('emoji_usage', ['none', 'light', 'heavy'])->default('none')->after('max_sentence_length');

            // Ich-Form vs. Wir-Form vs. neutral
            $table->enum('perspective', ['ich', 'wir', 'neutral'])->default('ich')->after('emoji_usage');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn(['forbidden_words', 'max_sentence_length', 'emoji_usage', 'perspective']);
        });
    }
};
