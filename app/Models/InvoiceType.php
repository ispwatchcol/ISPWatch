<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Tipo de factura del catálogo (Plan Mensual, Instalación, Equipos, TV...).
 *
 * NO usa BelongsToTenant a propósito: el scope global filtra por tenant_id y
 * dejaría fuera los tipos del sistema, que viven con tenant_id NULL y los
 * comparten todos los tenants. El filtrado se hace explícito en forTenant().
 */
class InvoiceType extends Model
{
    protected $table = 'invoice_types';

    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'color',
        'description',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_system'  => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['is_global'];

    /**
     * Paleta admitida. El nombre del color viaja al front, que lo traduce a
     * clases de Tailwind completas (si se guardaran clases, el purge de
     * producción se llevaría por delante las que no aparecen en el código).
     */
    public const COLORS = [
        'blue', 'emerald', 'purple', 'amber', 'rose',
        'cyan', 'indigo', 'orange', 'teal', 'slate',
    ];

    /** Slugs que crea la migración y que ningún tenant puede pisar ni borrar. */
    public const SYSTEM_SLUGS = [
        Invoice::TYPE_MONTHLY,
        Invoice::TYPE_INSTALLATION,
        Invoice::TYPE_ADDITIONAL,
        Invoice::TYPE_SERVICE_CHARGE,
    ];

    /** Los tipos del sistema no son de nadie: se muestran como solo-lectura. */
    public function getIsGlobalAttribute(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Tipos visibles para un tenant: los del sistema + los suyos.
     */
    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id');
            if ($tenantId) {
                $q->orWhere('tenant_id', $tenantId);
            }
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderByDesc('is_system')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * ¿Puede este tenant emitir una factura con este slug? Sólo tipos activos
     * del sistema o propios; nunca el tipo de otro tenant.
     */
    public static function isUsableSlug(?string $slug, ?int $tenantId): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return static::query()
            ->forTenant($tenantId)
            ->where('slug', $slug)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Slug a partir del nombre: "Factura de Equipos" → "factura_de_equipos".
     * Guion bajo y no guion medio para que combine con los slugs del sistema
     * (service_charge) y con el filtro `invoice_type` de la API.
     */
    public static function slugFromName(string $name): string
    {
        return Str::limit(Str::slug($name, '_'), 50, '');
    }
}
