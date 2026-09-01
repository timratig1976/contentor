<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quellen-Monitoring: URL + Frequenz + Hash für Change-Detection.
 * Der Monitor crawlt Quellen mit `monitor = true` in ihrer `frequency`
 * und vergleicht den Content-Hash, um neue Inhalte zu finden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('url')->nullable()->after('file_ref');
            $table->boolean('monitor')->default(false)->after('url');
            $table->string('frequency')->default('weekly')->after('monitor'); // daily, weekly, biweekly
            $table->timestamp('last_checked_at')->nullable()->after('frequency');
            $table->string('content_hash', 64)->nullable()->after('last_checked_at');
            $table->string('last_content_preview', 500)->nullable()->after('content_hash');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->index('monitor');
            $table->index('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex(['monitor']);
            $table->dropIndex(['last_checked_at']);
            $table->dropColumn([
                'url', 'monitor', 'frequency',
                'last_checked_at', 'content_hash', 'last_content_preview',
            ]);
        });
    }
};
