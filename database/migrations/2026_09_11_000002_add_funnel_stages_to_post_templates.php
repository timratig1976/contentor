<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_templates', function (Blueprint $table) {
            // Für welche Funnel-Stufen eignet sich dieses Template?
            // ToFu = Awareness, MoFu = Consideration, BoFu = Decision, all = überall
            $table->json('funnel_stages')->nullable()->after('best_for');
        });

        // newsletter_bk + newsletter_acquisition → newsletter zusammenführen
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('format')->default('linkedin_post')->change();
        });
        \DB::table('content_items')
            ->whereIn('format', ['newsletter_bk', 'newsletter_acquisition'])
            ->update(['format' => 'newsletter']);
    }

    public function down(): void
    {
        Schema::table('post_templates', function (Blueprint $table) {
            $table->dropColumn('funnel_stages');
        });
    }
};
