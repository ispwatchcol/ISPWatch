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
        // Motivo normalizado del desenlace (App\Support\ReconnectionOutcome).
        // Distinto de `reason`, que dice qué ORIGINÓ la acción; éste dice cómo
        // TERMINÓ, en un vocabulario cerrado que sí se puede filtrar y pintar.
        'outcome',
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
     * La reconexión pendiente vigente de un cliente, o null si no tiene ninguna.
     *
     * "Vigente" = el último movimiento de reconexión de este cliente quedó con
     * un desenlace pendiente y nadie lo ha resuelto después. Se mira el ÚLTIMO
     * UNSUSPEND y no "cualquiera pendiente" a propósito: una reconexión que
     * falló ayer y hoy salió bien no es un problema abierto, y sacarla como
     * alerta mandaría al operador a revisar un equipo que ya está bien.
     *
     * Es la fuente de la alerta persistente en la ficha del cliente: mientras
     * esta consulta devuelva una fila, el aviso sigue en pantalla.
     */
    public static function pendingReconnectionFor(int $customerId): ?self
    {
        $latest = static::where('customer_id', $customerId)
            ->where('action', self::ACTION_UNSUSPEND)
            ->latest('id')
            ->first();

        if (!$latest) {
            return null;
        }

        // Dos condiciones, no una: el motivo tiene que ser de los pendientes Y
        // la fila no puede estar cerrada en éxito. Con sólo el motivo, una fila
        // que un reintento dejó en `success` seguiría encendiendo la alerta si
        // alguien olvidara actualizarle el `outcome` — y una alerta que no se
        // apaga cuando el problema se resuelve deja de creerse.
        if ($latest->status === self::STATUS_SUCCESS) {
            return null;
        }

        return \App\Support\ReconnectionOutcome::isPending($latest->outcome) ? $latest : null;
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
