<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->string('id')->primary(); // SRC-xxx
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['pdf', 'url', 'interview', 'intern', 'research']);
            $table->string('visibility')->default('intern'); // intern, extern, partner
            $table->string('file_ref')->nullable();
            $table->string('batch_key')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'batch_key']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
