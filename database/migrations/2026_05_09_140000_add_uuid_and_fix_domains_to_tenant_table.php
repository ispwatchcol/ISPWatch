<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Adds a 'uuid' column to the tenant table (unique, stable identifier).
     * 2. Cleans up existing domains that were generated with a Unix timestamp
     *    suffix (e.g. "ispwatch-pruebas-1778274279" → "ispwatch-pruebas").
     */
    public function up(): void
    {
        // ── 1. Add UUID column ──────────────────────────────────────────────
        Schema::table('tenant', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Populate UUID for every existing tenant
        DB::table('tenant')->orderBy('id')->each(function ($tenant) {
            DB::table('tenant')
                ->where('id', $tenant->id)
                ->update(['uuid' => (string) Str::uuid()]);
        });

        // Make the column non-nullable once all rows have a value
        Schema::table('tenant', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });

        // ── 2. Clean timestamp suffix from existing domains ─────────────────
        // Pattern: domain ends with a hyphen followed by 9+ digits (Unix timestamp)
        // e.g. "ispwatch-pruebas-1778274279" → "ispwatch-pruebas"
        $tenants = DB::table('tenant')->get();

        foreach ($tenants as $tenant) {
            $cleanDomain = preg_replace('/-\d{9,}$/', '', $tenant->domain);

            if ($cleanDomain !== $tenant->domain) {
                // Ensure the cleaned domain is unique (add counter if collision)
                $finalDomain = $cleanDomain;
                $counter     = 2;

                while (
                    DB::table('tenant')
                        ->where('domain', $finalDomain)
                        ->where('id', '!=', $tenant->id)
                        ->exists()
                ) {
                    $finalDomain = $cleanDomain . '-' . $counter;
                    $counter++;
                }

                DB::table('tenant')
                    ->where('id', $tenant->id)
                    ->update(['domain' => $finalDomain]);
            }
        }
    }

    /**
     * Reverse the migrations.
     * NOTE: The domain cleanup is irreversible by design.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
