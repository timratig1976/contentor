<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persona_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('format');              // linkedin_post, newsletter_bk, etc.
            $table->text('content');              // Der Referenz-Post-Text
            $table->text('why_good')->nullable(); // Kurze Begründung (manuell kuratiert)
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['persona_id', 'format', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_examples');
    }
};