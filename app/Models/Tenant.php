<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'tenant';
    protected $fillable = [
        'name',
        'domain',
        'status',
        'max_customers',
        'logo',
        'email_tenant',
        'tel_tenant',
        'address_tenant',
        'zone_tenant',
        'currency_tenant',
        'timezone',
        'currency',
        'next_invoice_number',
    ];
}
