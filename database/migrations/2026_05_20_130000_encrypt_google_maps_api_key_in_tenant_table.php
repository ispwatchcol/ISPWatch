<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen column to TEXT so the base64-encoded encrypted payload fits (>255 chars).
        Schema::table('tenant', function (Blueprint $table) {
            $table->text('google_maps_api_key')->nullable()->change();
        });

        // Encrypt any plain-text values already stored.
        // Laravel encrypted strings are base64-encoded JSON (start with 'eyJ').
        $rows = DB::table('tenant')->whereNotNull('google_maps_api_key')->get(['id', 'google_maps_api_key']);

        foreach ($rows as $row) {
            $raw = $row->google_maps_api_key;
            if (empty($raw) || str_starts_with($raw, 'eyJ')) {
                continue;
            }
            DB::table('tenant')->where('id', $row->id)->update([
                'google_maps_api_key' => encrypt($raw),
            ]);
        }
    }

    public function down(): void
    {
        // Decrypt back to plain-text, then shrink column back to varchar(255).
        $rows = DB::table('tenant')->whereNotNull('google_maps_api_key')->get(['id', 'google_maps_api_key']);

        foreach ($rows as $row) {
            $raw = $row->google_maps_api_key;
            if (empty($raw)) continue;
            try {
                DB::table('tenant')->where('id', $row->id)->update([
                    'google_maps_api_key' => decrypt($raw),
                ]);
            } catch (\Exception $e) {
                // already plain-text or corrupt — skip
            }
        }

        Schema::table('tenant', function (Blueprint $table) {
            $table->string('google_maps_api_key', 255)->nullable()->change();
        });
    }
};
