<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'service_plan';

    protected $fillable = [
        'name',
        'speed_down',
        'speed_up',
        'cost_product',
        'is_courtesy',
        // "Primera factura" a nivel de producto: la promoción de instalación
        // suele venderse con el plan ("Hogar 100M: instalación con mes de
        // regalo"). null = hereda del router. Ver App\Billing\FirstInvoicePolicy.
        'first_invoice_mode',
        'first_invoice_free_months',
        'commit',
        'type',
        'tenant_id',
        'type_plan_id',
        // Campos específicos por tipo de plan
        'priority',           // Queue
        'burst_download',     // Queue/PPPoE
        'burst_upload',       // Queue/PPPoE
        'pppoe_pool',         // PPPoE
        'local_address',      // PPPoE
        'shared_users',       // Hotspot
        'session_timeout',    // Hotspot
        'idle_timeout',       // Hotspot
        'pcq_rate',           // PCQ
        'address_mask',       // PCQ
    ];

    protected $appends = ['active_clients_count'];

    protected $casts = [
        'is_courtesy' => 'boolean',
        'first_invoice_free_months' => 'integer',
    ];

    public function typePlan()
    {
        return $this->belongsTo(TypePlan::class, 'type_plan_id');
    }

    public function userServices()
    {
        return $this->hasMany(UserService::class, 'service_plan_id');
    }

    public function activeClients()
    {
        return $this->userServices()
            ->whereHas('user', fn($q) => $q->where('status', true)->where('role_id', 3))
            ->whereIn('status', [UserService::STATUS_ACTIVE, UserService::STATUS_GRATIS]);
    }

    public function getActiveClientsCountAttribute()
    {
        return $this->activeClients()->count();
    }

    public function scopeByTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
