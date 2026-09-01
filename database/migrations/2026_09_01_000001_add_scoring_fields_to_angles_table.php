<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('angles', function (Blueprint $table) {
            // Embedding-Vektor (JSON-serialisiert, DB-agnostisch statt pgvector)
            $table->text('embedding')->nullable();
            $table->text('score_reasoning')->nullable();
            $table->string('duplicate_of_id')->nullable();
            $table->float('similarity_score')->nullable();
        });

        Schema::table('angles', function (Blueprint $table) {
            $table->foreign('duplicate_of_id')
                ->references('id')->on('angles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('angles', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropColumn(['embedding', 'score_reasoning', 'duplicate_of_id', 'similarity_score']);
        });
    }
};
