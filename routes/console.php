<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run daily — BillingService checks each router's billing.create_invoice day internally
Schedule::command('billing:generate-monthly')->daily();

// Auto-cut: run every hour so it picks up routers whose cut_time has arrived
Schedule::command('billing:auto-cut')->hourly();

// Payment reminders: run daily — the service fires on each router's
// billing.payment_reminder day and is idempotent per billing cycle
Schedule::command('billing:send-reminders')->daily();

