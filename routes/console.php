<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\BillingService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:generate-monthly', function (BillingService $service) {
    $this->info("Starting monthly invoice generation...");
    $count = $service->generateMonthlyInvoices();
    $this->info("Generated $count invoices.");
})->purpose('Generate monthly invoices for active customers');

// Run on the 1st of every month at 00:00
Schedule::command('billing:generate-monthly')->monthlyOn(1, '00:00');

// Auto-cut: run every hour so it picks up routers whose cut_time has arrived
Schedule::command('billing:auto-cut')->hourly();
