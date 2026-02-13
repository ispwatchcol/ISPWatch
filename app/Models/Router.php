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
        'lan_interface',
        'wan_interface',
        'vpn_username',
        'vpn_password',
        'comments',
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
        'ip_bindings',
        'amarre',
        'dhcp_leases',
        'falla_general',
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
    ];


    public function cutType()
    {
        return $this->belongsTo(CutType::class, 'cut_type_id');
    }

    public function suspensionLogs()
    {
        return $this->hasMany(SuspensionActionLog::class);
    }
}
