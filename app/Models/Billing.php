<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    protected $table = 'billing';

    protected $fillable = [
        'id_type',
        'create_invoice',
        'payment_day',
        'payment_reminder',
        'cut_day',
        'cut_time',
        'overdue_invoices',
        'amount',
        'status',
        'notificar_wpp',
        'notification_type',
        'comments',
    ];

    protected $casts = [
        'create_invoice' => 'date',
        'payment_day' => 'date',
        'payment_reminder' => 'date',
        'cut_day' => 'date',
        'overdue_invoices' => 'integer',
        'amount' => 'decimal:2',
        'notificar_wpp' => 'boolean',
    ];

    /**
     * Routers that use this billing config.
     */
    public function routers()
    {
        return $this->hasMany(Router::class, 'billing_router_id');
    }

    /**
     * Get the day-of-month number stored in cut_day (1–31).
     * cut_day is cast to a Carbon date; we extract the day component.
     */
    public function getCutDayOfMonthAttribute(): ?int
    {
        if (!$this->cut_day) {
            return null;
        }
        /** @var Carbon $cutDate */
        $cutDate = $this->cut_day;
        return (int) $cutDate->format('j');
    }
}
