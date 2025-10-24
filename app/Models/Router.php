<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    protected $table = 'router';
    protected $fillable = [
        'name',
        'ip',
        'user_rb',
        'password_rb',
        'lan_interface',
        'comments',
        'cut_type_id',
        'billing_router_id',
        'firmware_version',
        'status',
        'coordinates',
    ];

    public $timestamps = true;
}
