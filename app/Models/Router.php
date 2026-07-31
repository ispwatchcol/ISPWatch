<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use BelongsToTenant;

    protected $table = 'router';
    protected $fillable = [
        'name',
        'tenant_id',
        'ip',
        'ipv6',
        'failover',
        'external_id',
        'user_rb',
        'password_rb',
        'puerto_api',
        'puerto_www',
        'puerto_ssh',
        'lan_interface',
        'wan_interface',
        'vpn_username',
        'vpn_password',
        'comments',
        'rangos_ip',
        'cut_type_id',
        'billing_router_id',
        'firmware_version',
        'status',
        'coordinates',
        'agregar_cliente_mkt',
        'historial_trafico',
        'simple_queue',
        'control_pcq',
        'hotspot',
        'pppoe',
        'pppoe_limit_mode',
        'ip_bindings',
        'amarre',
        'dhcp_leases',
        'falla_general',
        'vpn_transport',
        'wg_private_key',
        'wg_public_key',
        'wg_address',
        'wg_listen_port',
    ];

    public $timestamps = true;

    protected $casts = [
        'coordinates' => 'json',
        'agregar_cliente_mkt' => 'boolean',
        'historial_trafico' => 'boolean',
        'simple_queue' => 'boolean',
        'control_pcq' => 'boolean',
        'hotspot' => 'boolean',
        'pppoe' => 'boolean',
        'ip_bindings' => 'boolean',
        'amarre' => 'boolean',
        'dhcp_leases' => 'boolean',
        'falla_general' => 'boolean',

        // Credenciales cifradas en reposo. El cast descifra de forma transparente
        // al leer, así que todo el código sigue usando $router->password_rb.
        //
        // Historia, para que no se repita: la migración 2026_05_14_000001 copió
        // texto PLANO a unas columnas `*_encrypted` con un UPDATE de SQL crudo,
        // dando por hecho que el cast cifraría. El cast cifra al escribir POR
        // MODELO, no en SQL crudo, así que aquellas columnas quedaron en claro y
        // el cast lanzaba DecryptException en cada lectura. Se desactivó y las
        // credenciales siguieron en texto plano hasta la migración
        // 2026_07_31_000002, que cifra EN LA MISMA COLUMNA (con el modelo) y
        // elimina las `*_encrypted` duplicadas.
        //
        // Precaución: un valor cifrado NO es consultable en SQL. No añadas aquí
        // ninguna columna por la que se filtre (p. ej. pppoe_username, que tiene
        // índice único por router).
        'user_rb' => 'encrypted',
        'password_rb' => 'encrypted',
        'vpn_username' => 'encrypted',
        'vpn_password' => 'encrypted',

        // wg_public_key queda SIN cifrar a propósito: se compara contra los peers
        // que el CORE tiene registrados, y un valor cifrado no es consultable.
        'wg_private_key' => 'encrypted',
    ];

    // NOTA: password_rb y vpn_password NO están en $hidden a propósito.
    // El formulario de edición de router (resources/js/pages/RouterEdit.vue)
    // prellena el campo con `data.password_rb` y lo reenvía tal cual al guardar;
    // ocultarlo haría que el formulario cargara vacío y SOBRESCRIBIERA la
    // credencial con una cadena vacía al primer guardado — pérdida de datos.
    // Sacarlos de la respuesta exige antes cambiar el formulario a "dejar en
    // blanco para conservar la contraseña actual". Anotado en MEJORAS_RECOMENDADAS.


    /** Transportes VPN admitidos entre el CORE y este router. */
    public const TRANSPORT_L2TP      = 'l2tp';
    public const TRANSPORT_WIREGUARD = 'wireguard';

    /**
     * Port the CORE must dial when it opens `/system ssh-exec` to this router.
     * RouterOS defaults to 22; deployments that move SSH elsewhere set
     * puerto_ssh.
     *
     * Ojo con llenar puerto_ssh a mano: el script de provisión ejecuta
     * `/ip service set ssh port=22` en el cliente, así que PISA cualquier otro
     * valor en el siguiente push. Si un equipo debe servir SSH fuera del 22,
     * hay que cambiar también el script, no solo este campo.
     */
    public function sshPort(): int
    {
        $port = (int) ($this->puerto_ssh ?? 0);

        return $port > 0 ? $port : 22;
    }

    /**
     * Transporte del túnel. Por defecto L2TP: es lo que corre la flota vieja y
     * lo único que soporta RouterOS v6.
     */
    public function vpnTransport(): string
    {
        return $this->vpn_transport === self::TRANSPORT_WIREGUARD
            ? self::TRANSPORT_WIREGUARD
            : self::TRANSPORT_L2TP;
    }

    public function usesWireguard(): bool
    {
        return $this->vpnTransport() === self::TRANSPORT_WIREGUARD;
    }

    /**
     * ¿El firmware admite WireGuard? Existe desde RouterOS 7.1; en v6 no hay.
     * Formatos que llegan de `/system resource`: "7.23.1 (stable)", "6.49.10",
     * "7.1rc4". Ante un valor vacío o ilegible devolvemos false: preferimos
     * dejar el router en L2TP (que funciona en ambas ramas) antes que emitirle
     * un script que su RouterOS va a rechazar entero.
     */
    public static function firmwareSupportsWireguard(?string $version): bool
    {
        if (!preg_match('/(\d+)\.(\d+)/', (string) $version, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];

        return $major > 7 || ($major === 7 && $minor >= 1);
    }

    public function cutType()
    {
        return $this->belongsTo(CutType::class, 'cut_type_id');
    }

    public function billingConfig()
    {
        return $this->belongsTo(Billing::class, 'billing_router_id');
    }

    /**
     * Alias of billingConfig() that serializes under the `billing` key, which
     * is the shape the router add/edit form expects (data.billing.*).
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class, 'billing_router_id');
    }

    public function suspensionLogs()
    {
        return $this->hasMany(SuspensionActionLog::class);
    }

    public function customers()
    {
        return $this->hasMany(CustomerProfile::class, 'router_id');
    }
}
