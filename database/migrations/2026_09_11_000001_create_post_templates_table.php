<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zentraler, globaler Template-Katalog — Single Source of Truth statt
     * der bisherigen hardcodierten Arrays in Strategie/Index.vue UND
     * Templates/Index.vue (identischer Inhalt, doppelt gepflegt).
     *
     * Pro Strategie wird weiterhin eine AUSWAHL (welche Templates aktiv
     * sind) in ContentStrategy.key='post_templates' gespeichert — aber
     * die Katalog-Definition selbst lebt nur noch hier.
     */
    public function up(): void
    {
        Schema::create('post_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('format'); // linkedin_post, ad_copy, newsletter_bk, ...
            $table->string('description')->nullable();
            $table->text('structure'); // "Schritt 1\nSchritt 2\n..."
            $table->text('example')->nullable();
            $table->json('best_for')->nullable(); // ICP-Keys
            $table->enum('source', ['builtin', 'custom', 'ai_generated'])->default('custom');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_templates');
    }
};
