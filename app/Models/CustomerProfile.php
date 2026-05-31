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
        'cedula',
        'department',
        'position',
        'address',
        'precinto',
        'city',
        'installation_date',
        'estrato',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'credit_balance',
        'ip_user',
        'service_id',
        'sectorial_id',
        'nap_port',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }
}
