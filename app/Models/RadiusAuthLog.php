<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de autenticaciones RADIUS.
 *
 * Existe para que soporte pueda contestar "¿por qué no conecta este cliente?"
 * sin abrir Winbox. Por eso los motivos son constantes estables y no mensajes
 * libres: se muestran y se filtran en el panel.
 *
 * Sobre tenant_id: aplica la misma advertencia que RadiusSession — sin usuario
 * autenticado el trait no lo rellena, hay que asignarlo desde el router.
 */
class RadiusAuthLog extends Model
{
    use BelongsToTenant;

    protected $table = 'radius_auth_logs';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'customer_id',
        'username',
        'accepted',
        'reason',
        'profile',
        'nas_ip_address',
        'calling_station_id',
    ];

    protected $casts = [
        'accepted' => 'boolean',
    ];

    /**
     * Motivos de rechazo. Cambiar estas cadenas rompe los filtros guardados del
     * panel, así que se agregan valores nuevos en vez de renombrar los viejos.
     */
    public const REASON_OK             = 'ok';
    public const REASON_OVERDUE        = 'overdue';
    public const REASON_UNKNOWN_USER   = 'unknown_user';
    public const REASON_BAD_PASSWORD   = 'bad_password';
    public const REASON_NOT_RADIUS     = 'router_not_radius';
    public const REASON_NO_ROUTER      = 'router_not_found';
    public const REASON_SERVICE_ENDED  = 'service_ended';

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    public function customer()
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id', 'user_id');
    }
}
