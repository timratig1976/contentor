<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->text('voice')->nullable();
            $table->jsonb('topics')->nullable();
            $table->string('cadence')->nullable(); // weekly, biweekly, monthly
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['strategy_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
