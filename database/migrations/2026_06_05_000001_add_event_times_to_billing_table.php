<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds the hour-of-day for the two billing events that until now only had
     * a day-of-month: invoice creation and payment reminders. The cut already
     * has cut_time (see 2026_02_27_000001). Default '00:00:00' keeps the exact
     * current behaviour (fire at the first run of the configured day) for every
     * existing row — the hour is purely opt-in.
     */
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            if (!Schema::hasColumn('billing', 'create_invoice_time')) {
                $table->time('create_invoice_time')->default('00:00:00')->after('create_invoice')
                    ->comment('Hora del día en que se genera la factura mensual');
            }
            if (!Schema::hasColumn('billing', 'payment_reminder_time')) {
                $table->time('payment_reminder_time')->default('00:00:00')->after('payment_reminder')
                    ->comment('Hora del día en que se envía el recordatorio de pago');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            foreach (['create_invoice_time', 'payment_reminder_time'] as $col) {
                if (Schema::hasColumn('billing', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
