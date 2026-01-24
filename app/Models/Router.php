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
        'wan_interface',     // Agregado
        'comments',
        'cut_type_id',
        'billing_router_id',
        'firmware_version',
        'status',
        'coordinates',
    ];

    public $timestamps = true;

    protected $casts = [
        'coordinates' => 'json',
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
