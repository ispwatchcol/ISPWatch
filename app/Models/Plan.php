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
