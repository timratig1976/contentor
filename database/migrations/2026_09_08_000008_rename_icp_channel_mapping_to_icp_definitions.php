<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_strategies')) {
            return;
        }

        // Code (ContentStrategy::KEYS) + UI nutzen seit jeher "icp_definitions".
        // Bestehende Daten mit dem alten Key "icp_channel_mapping" mitziehen,
        // damit sie nicht verwaist unsichtbar bleiben.
        $legacy = DB::table('content_strategies')
            ->where('key', 'icp_channel_mapping')
            ->get();

        foreach ($legacy as $row) {
            // Wenn eine Strategie bereits einen "icp_definitions"-Datensatz hat,
            // gewinnt das neue Format -> Legacy-Zeile entfernen (unique constraint).
            $alreadyExists = DB::table('content_strategies')
                ->where('strategy_id', $row->strategy_id)
                ->where('key', 'icp_definitions')
                ->exists();

            if ($alreadyExists) {
                DB::table('content_strategies')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('content_strategies')
                ->where('id', $row->id)
                ->update(['key' => 'icp_definitions']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_strategies')) {
            return;
        }

        DB::table('content_strategies')
            ->where('key', 'icp_definitions')
            ->update(['key' => 'icp_channel_mapping']);
    }
};