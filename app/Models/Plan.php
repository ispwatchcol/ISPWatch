<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'service_plan';

    protected $fillable = [
        'name',
        'speed_down',
        'speed_up',
        'cost_product',
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

    public function typePlan()
    {
        return $this->belongsTo(TypePlan::class, 'type_plan_id');
    }

    public function activeClients()
    {
        return $this->hasMany(User::class, 'service_id')
            ->where('role_id', 3)
            ->where('status', true);
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
