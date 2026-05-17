<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use BelongsToTenant;

    protected $table = 'service_plan';

    protected $fillable = [
        'name',
        'speed_down',
        'speed_up',
        'cost_product',
        'is_courtesy',
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
            ->where('status', 'active');
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
