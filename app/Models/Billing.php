<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    protected $table = 'billing';

    /** Invoice covers the month the job runs (cobro por adelantado). */
    public const MODE_ANTICIPADO = 'anticipado';

    /** Invoice covers the previous month (cobro del mes consumido). */
    public const MODE_VENCIDO = 'vencido';

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
        'billing_mode',
        'notificar_wpp',
        'notification_type',
        'comments',
    ];

    protected $attributes = [
        'billing_mode' => self::MODE_ANTICIPADO,
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

    /**
     * Extract the day-of-month (1–31) from a date-cast column value.
     */
    public static function dayOf($dateValue): ?int
    {
        if (!$dateValue) {
            return null;
        }
        return Carbon::parse($dateValue)->day;
    }

    /**
     * Clamp a configured day-of-month to the actual length of $reference's
     * month. Example: a configured day 31 becomes 30 in April and 28/29 in
     * February, while a configured day 5 stays 5. This makes "último día"
     * configs behave as the operator expects across all months.
     */
    public static function clampDayToMonth(?int $day, Carbon $reference): ?int
    {
        if ($day === null) {
            return null;
        }
        return min($day, $reference->daysInMonth);
    }
}
