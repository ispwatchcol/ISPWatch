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

    /**
     * Relación con tipo de plan
     */
    public function typePlan()
    {
        return $this->belongsTo(TypePlan::class, 'type_plan_id');
    }

    /**
     * Usuarios asociados al plan
     */
    public function users()
    {
        return $this->hasMany(User::class, 'service_id');
    }

    /**
     * Scope para traer el conteo de clientes activos
     */
    protected static function booted()
    {
        static::addGlobalScope('active_clients_count', function ($query) {
            $query->withCount([
                'users as active_clients_count' => function ($q) {
                    $q->where('role_id', 3)
                      ->where('status', true);
                }
            ]);
        });
    }
}
