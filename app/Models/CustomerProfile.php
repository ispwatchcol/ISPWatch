<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $table = 'customer_profile';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'last_name',
        'is_company',
        'cedula',
        'department',
        'position',
        'address',
        'precinto',
        'city',
        'installation_date',
        'estrato',
        'exclude_from_billing',
        'comments',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'credit_balance',
        'ip_user',
        'service_id',
        'sectorial_id',
        'olt_id',
        'nap_port',
        'is_fiber',
        'router_id',
        'pppoe_username',
        'pppoe_password',
        'pppoe_local_address',
        'hotspot_username',
        'hotspot_password',
        'mac_address',
        'status',
        'service_status',
    ];

    protected $casts = [
        'is_company'           => 'boolean',
        'is_fiber'             => 'boolean',
        'exclude_from_billing' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    /** OLT de fibra (sectorial element_type=olt) asignada al cliente. */
    public function olt()
    {
        return $this->belongsTo(Sectorial::class, 'olt_id');
    }
}
