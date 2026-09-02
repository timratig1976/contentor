<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            // Gruppierung für A/B-Varianten (UUID, null = keine Variante)
            $table->uuid('variant_group_id')->nullable()->after('status');
            // variant_pattern: story | listicle | contrarian | question | data_drop
            $table->string('variant_pattern')->nullable()->after('variant_group_id');

            $table->index('variant_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['variant_group_id']);
            $table->dropColumn(['variant_group_id', 'variant_pattern']);
        });
    }
};
