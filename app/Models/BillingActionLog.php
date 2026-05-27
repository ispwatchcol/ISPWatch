<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingActionLog extends Model
{
    public const ACTION_GENERATE_MONTHLY = 'generate_monthly_invoice';

    public const STATUS_SUCCESS   = 'success';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_EXHAUSTED = 'exhausted';

    public const MAX_ATTEMPTS = 3;

    // Backoff escalonado en segundos: tras intento 1, 2, 3
    public const RETRY_BACKOFF_SECONDS = [
        1 => 2 * 3600,    //  2h tras 1er intento
        2 => 6 * 3600,    //  6h tras 2do intento
        3 => 24 * 3600,   // 24h tras 3er intento (informativo; ya entra a exhausted)
    ];

    protected $fillable = [
        'tenant_id',
        'router_id',
        'customer_id',
        'invoice_id',
        'action',
        'period_start',
        'period_end',
        'status',
        'attempts',
        'last_error',
        'next_retry_at',
    ];

    protected $casts = [
        'period_start'  => 'date',
        'period_end'    => 'date',
        'next_retry_at' => 'datetime',
        'attempts'      => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_EXHAUSTED;
    }

    public function isReadyToRetry(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if ($this->next_retry_at && $this->next_retry_at->isFuture()) {
            return false;
        }
        return true;
    }
}
