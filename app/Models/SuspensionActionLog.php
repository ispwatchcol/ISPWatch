<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuspensionActionLog extends Model
{
    public const ACTION_SUSPEND        = 'SUSPEND';
    public const ACTION_UNSUSPEND      = 'UNSUSPEND';
    public const ACTION_INSTALL_POLICY = 'INSTALL_POLICY';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_PENDING = 'pending';

    public const REASON_MANUAL         = 'manual';
    public const REASON_AUTO_CUT       = 'auto_cut_overdue';
    public const REASON_RECONCILE      = 'reconcile';
    public const REASON_AUTO_RECONNECT = 'auto_reconnect_paid';

    public const MAX_ATTEMPTS = 4;

    // Backoff escalonado en segundos: tras intento 1, 2, 3, 4.
    // Un corte sin aplicar es fuga de ingreso, por eso arranca agresivo (30m).
    public const RETRY_BACKOFF_SECONDS = [
        1 => 1800,    // 30m tras 1er intento
        2 => 7200,    //  2h tras 2do intento
        3 => 21600,   //  6h tras 3er intento
        4 => 86400,   // 24h tras 4to intento (informativo; ya entra a agotado)
    ];

    protected $fillable = [
        'router_id',
        'customer_id',
        'ip',
        'action',
        'reason',
        'status',
        'attempts',
        'error_message',
        'next_retry_at',
    ];

    protected $casts = [
        'attempts'      => 'integer',
        'next_retry_at' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Agotado = falló y ya consumió todos los intentos. Estado derivado:
     * requiere acción manual (no se reintenta automáticamente).
     */
    public function isExhausted(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * Listo para reintentar = falló, aún quedan intentos y el backoff venció.
     */
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
