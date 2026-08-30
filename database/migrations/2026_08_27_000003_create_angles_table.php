<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('angles', function (Blueprint $table) {
            $table->string('id')->primary(); // ANG-xxx
            $table->string('source_id')->nullable();
            $table->foreign('source_id')->references('id')->on('sources')->nullOnDelete();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('batch_key')->nullable();
            $table->text('angle');
            $table->string('icp')->nullable();
            $table->string('pain_cluster')->nullable();
            $table->string('statement_type')->nullable();
            $table->enum('funnel', ['ToFu', 'MoFu', 'BoFu'])->nullable();
            $table->string('viscale_phase')->nullable();
            $table->integer('r_zielgruppe')->nullable(); // 1-3
            $table->integer('r_viscale_fit')->nullable();
            $table->integer('r_schaerfe')->nullable();
            $table->integer('r_timing')->nullable();
            $table->integer('ranking_score')->nullable();
            $table->integer('ranking_rang')->nullable();
            $table->string('status')->default('neu'); // neu, bewertet, approved, verworfen
            $table->timestamps();

            $table->index(['strategy_id', 'batch_key']);
            $table->index(['strategy_id', 'status']);
            $table->index('source_id');
            $table->index('icp');
            $table->index('funnel');
            $table->index('ranking_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('angles');
    }
};
