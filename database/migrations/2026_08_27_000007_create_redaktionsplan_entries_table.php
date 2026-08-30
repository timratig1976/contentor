<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redaktionsplan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('content_item_id');
            $table->foreign('content_item_id')->references('id')->on('content_items')->cascadeOnDelete();
            $table->date('planned_date');
            $table->string('channel')->nullable(); // linkedin_organic, paid_social, newsletter, etc.
            $table->string('status')->default('geplant'); // geplant, in_arbeit, fertig, live
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['strategy_id', 'planned_date']);
            $table->index(['strategy_id', 'status']);
            $table->index('content_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redaktionsplan_entries');
    }
};
