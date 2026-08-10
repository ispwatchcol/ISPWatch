<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Consumidor externo de la API pública de solo lectura.
 *
 * Es "autenticable" sólo en el sentido que le importa a Sanctum: el guard
 * resuelve el token a este modelo y `$request->user()` lo devuelve. No tiene
 * contraseña ni pasa nunca por AuthController::login — la única credencial es
 * el token, y la única forma de obtenerlo es que un administrador del tenant
 * operador lo emita desde Configuración → Llaves API.
 *
 * Extiende Authenticatable (y no Model a secas) porque el guard de Sanctum y
 * el middleware `auth:` esperan un contrato Authenticatable. getAuthPassword()
 * devuelve cadena vacía: no hay flujo que la consulte, pero dejarla nula haría
 * explotar cualquier código que asuma string.
 *
 * `tenant_id` es la pieza central del aislamiento: el global scope de
 * App\Traits\BelongsToTenant lee `auth()->user()->tenant_id`, así que un
 * ApiClient queda encerrado en su tenant por el mismo mecanismo que un usuario
 * humano, sin código de aislamiento paralelo que pueda divergir.
 */
class ApiClient extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'api_clients';

    protected $fillable = [
        'tenant_id',
        'name',
        'contact_email',
        'description',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * No hay contraseña: la autenticación es exclusivamente por token.
     * Se devuelve cadena vacía (nunca un hash válido) para que ningún
     * Hash::check contra este modelo pueda dar verdadero por accidente.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(ApiKeyRequestLog::class, 'api_client_id');
    }
}
