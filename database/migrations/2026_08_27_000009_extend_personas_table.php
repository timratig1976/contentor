<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->jsonb('core_statements')->nullable()->after('voice');
            $table->jsonb('tonality')->nullable()->after('core_statements');
            $table->string('positioning')->nullable()->after('tonality');
            $table->jsonb('channel_strategies')->nullable()->after('cadence');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn(['core_statements', 'tonality', 'positioning', 'channel_strategies']);
        });
    }
};