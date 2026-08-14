<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Orden de CoA / Disconnect pendiente de entregar a un NAS.
 *
 * El emisor real es un agente que corre en el host del FreeRADIUS (el único
 * que alcanza a los routers dentro del overlay VPN). Esta tabla es el contrato
 * entre ISPWatch y ese agente: ISPWatch encola, el agente toma, ejecuta
 * radclient y confirma.
 *
 * Ver la migración 2026_08_14_100300 para el porqué de la cola.
 */
class RadiusCoaCommand extends Model
{
    use BelongsToTenant;

    protected $table = 'radius_coa_commands';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'customer_id',
        'username',
        'action',
        'reason',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'dispatched_at',
        'confirmed_at',
    ];

    protected $casts = [
        'attempts'        => 'integer',
        'next_attempt_at' => 'datetime',
        'dispatched_at'   => 'datetime',
        'confirmed_at'    => 'datetime',
    ];

    public const ACTION_DISCONNECT = 'DISCONNECT';
    public const ACTION_COA        = 'COA';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SENT      = 'sent';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED    = 'failed';

    /** Por qué se emitió la orden. */
    public const REASON_OVERDUE      = 'overdue';
    public const REASON_REACTIVATED  = 'reactivated';
    public const REASON_PLAN_CHANGED = 'plan_changed';
    public const REASON_MANUAL       = 'manual';

    /**
     * Tope de reintentos antes de marcar la orden como fallida.
     *
     * Se eligió bajo a propósito: si cinco intentos no alcanzaron al NAS, el
     * problema es de red o de configuración del router, no algo que un sexto
     * intento vaya a resolver. Que quede en 'failed' es lo que hace que
     * aparezca en el panel en vez de reintentar para siempre en silencio.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Backoff exponencial en segundos: 30s, 1m, 2m, 4m, 8m.
     */
    public static function backoffSeconds(int $attempts): int
    {
        return 30 * (2 ** max(0, $attempts - 1));
    }

    /**
     * Órdenes que el agente puede tomar ahora mismo.
     *
     * Incluye 'sent' además de 'pending': una orden que salió pero nunca se
     * confirmó tiene que volver a intentarse. El CoA es UDP — que radclient no
     * reportara error no prueba que el NAS lo haya recibido.
     */
    public function scopeDue($query)
    {
        return $query
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            });
    }

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id', 'user_id');
    }
}
