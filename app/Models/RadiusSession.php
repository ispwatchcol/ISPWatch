<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Sesión RADIUS, alimentada por los paquetes de Accounting del NAS.
 *
 * CUIDADO CON EL tenant_id EN CONTEXTO RADIUS
 * --------------------------------------------
 * BelongsToTenant rellena tenant_id desde auth()->user() al crear, pero los
 * endpoints RADIUS son máquina-a-máquina: NO hay usuario autenticado, así que
 * ese hook no dispara y el scope global tampoco filtra nada.
 *
 * Consecuencia práctica: quien escriba desde el pipeline de accounting DEBE
 * asignar tenant_id explícitamente, tomándolo del router registrado —
 * jamás de un campo que venga en el request. Si se olvida, la fila queda con
 * tenant_id null y desaparece del panel (que sí filtra por inquilino).
 */
class RadiusSession extends Model
{
    use BelongsToTenant;

    protected $table = 'radius_sessions';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'customer_id',
        'username',
        'acct_session_id',
        'acct_unique_id',
        'nas_ip_address',
        'framed_ip_address',
        'calling_station_id',
        'called_station_id',
        'started_at',
        'last_interim_at',
        'stopped_at',
        'input_octets',
        'output_octets',
        'session_time',
        'terminate_cause',
        'profile',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'last_interim_at' => 'datetime',
        'stopped_at'      => 'datetime',
        'input_octets'    => 'integer',
        'output_octets'   => 'integer',
        'session_time'    => 'integer',
    ];

    /** Perfil con el que se respondió al cliente. */
    public const PROFILE_NORMAL   = 'normal';
    public const PROFILE_OVERDUE  = 'moroso';

    /**
     * Sesiones todavía abiertas. Es la consulta del reconciliador de cortes:
     * un cliente suspendido en BD que siga apareciendo aquí no se cortó de
     * verdad y hay que reencolarle el Disconnect.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('stopped_at');
    }

    /**
     * Recompone el contador de 64 bits que RADIUS parte en dos atributos.
     *
     * Acct-Input-Octets es de 32 bits y desborda cada 4 GB; el excedente viaja
     * en Acct-Input-Gigawords. Sumar solo el primero subcuenta el tráfico de
     * cualquier sesión larga, que es un error silencioso y difícil de ver.
     */
    public static function combineOctets(?int $octets, ?int $gigawords): int
    {
        return ((int) $gigawords << 32) + (int) $octets;
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
