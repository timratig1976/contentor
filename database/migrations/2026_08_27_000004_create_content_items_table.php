<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->string('id')->primary(); // CNT-xxx
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('angle_id')->nullable();
            $table->foreign('angle_id')->references('id')->on('angles')->nullOnDelete();
            $table->enum('type', ['idea', 'post', 'newsletter'])->default('idea');
            $table->string('format')->nullable(); // linkedin_post, ad_copy, newsletter_bk, etc.
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('status')->default('idee'); // idee, angle, in_produktion, review, geplant, live, verworfen
            $table->string('owner')->nullable();
            $table->date('live_date')->nullable();
            $table->string('persona_id')->nullable();
            $table->string('icp')->nullable();
            $table->string('pain_cluster')->nullable();
            $table->string('statement_type')->nullable();
            $table->timestamps();

            $table->index(['strategy_id', 'status']);
            $table->index(['strategy_id', 'type']);
            $table->index('angle_id');
            $table->index('icp');
            $table->index('live_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
