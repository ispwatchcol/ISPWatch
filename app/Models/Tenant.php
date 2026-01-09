<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'tenant';
    protected $fillable = [
        'name',
        'domain',
        'logo',
        'email_tenant',
        'tel_tenant',
        'address_tenant',
        'zone_tenant',
        'currency_tenant',
        'timezone',
        'currency',
    ];
}
