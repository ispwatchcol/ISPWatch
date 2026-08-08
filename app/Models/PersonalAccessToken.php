<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Token de Sanctum extendido con los metadatos de las llaves de la API pública.
 *
 * Se registra en AppServiceProvider con Sanctum::usePersonalAccessTokenModel()
 * para que `findToken()` (y por tanto el guard) devuelva esta clase y las
 * columnas nuevas lleguen ya casteadas al middleware.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'allowed_ips',
        'created_by',
    ];

    protected $casts = [
        'abilities'    => 'json',
        'allowed_ips'  => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /**
     * Revocada a mano desde el panel. Se distingue de la caducidad porque la
     * revocación es una decisión humana (llave filtrada, contrato terminado) y
     * conviene poder contarla aparte en la auditoría.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * ¿Esta IP puede usar la llave?
     *
     * IpUtils::checkIp resuelve IPv4, IPv6 y notación CIDR ("190.24.7.0/24"),
     * que es justo lo que se le pide a un ISP cuyo servidor sale por un bloque.
     *
     * Devuelve false si la lista está vacía: la allowlist es OBLIGATORIA en
     * esta API. Una llave sin IPs registradas no sirve para nada — que falle
     * cerrado es deliberado, para que un error de configuración no termine en
     * una llave abierta al mundo.
     */
    public function allowsIp(?string $ip): bool
    {
        $allowed = array_filter((array) ($this->allowed_ips ?? []));

        if ($allowed === [] || $ip === null || $ip === '') {
            return false;
        }

        return IpUtils::checkIp($ip, array_values($allowed));
    }
}
