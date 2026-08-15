<?php

namespace App\Models;

use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'source',
        'description',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registrar evento de auditoría.
     *
     * No lleva el scope global de tenant a propósito: la escritura ocurre
     * también desde el scheduler y desde comandos de consola, donde no hay
     * sesión. El tenant se estampa explícito (o se resuelve del actor) y el
     * filtrado por sede se hace al leer, en scopeForTenant.
     */
    public static function log(array $data)
    {
        return static::create([
            'tenant_id'   => $data['tenant_id'] ?? AuditContext::currentTenantId(),
            'user_id'     => $data['user_id'] ?? AuditContext::actorId(),
            'action'      => $data['action'],
            'model_type'  => $data['model_type'] ?? null,
            'model_id'    => $data['model_id'] ?? null,
            'old_values'  => $data['old_values'] ?? null,
            'new_values'  => $data['new_values'] ?? null,
            'ip_address'  => AuditContext::ip(),
            'user_agent'  => AuditContext::userAgent(),
            'source'      => $data['source'] ?? AuditContext::source(),
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * Un operador solo puede ver la bitácora de su propia sede. Los registros
     * anteriores a la columna tenant_id quedan fuera: es preferible no
     * mostrarlos a arriesgar una fuga entre tenants.
     */
    public function scopeForTenant($query, ?int $tenantId)
    {
        return $tenantId ? $query->where('tenant_id', $tenantId) : $query;
    }
}
