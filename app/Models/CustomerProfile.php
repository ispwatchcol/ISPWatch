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
        'department',
        'position',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'ip_user',
        'service_id',
        'sectorial_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
