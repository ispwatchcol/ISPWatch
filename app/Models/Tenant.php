<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $table = 'tenant';
    protected $fillable = [
        'name',
        'domain',
        'email_tenant',
        'tel',
        'address',
        'timezone',
        'currency',
    ];
}
