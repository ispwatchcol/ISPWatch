<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficDaily extends Model
{
    protected $table = 'traffic_daily';

    protected $fillable = [
        'router_id', 'day', 'rx_bytes', 'tx_bytes',
    ];

    protected $casts = [
        'router_id' => 'integer',
        'day'       => 'date',
        'rx_bytes'  => 'integer',
        'tx_bytes'  => 'integer',
    ];
}
