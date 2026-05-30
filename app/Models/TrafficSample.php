<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficSample extends Model
{
    protected $table = 'traffic_samples';

    protected $fillable = [
        'router_id', 'rx_bytes', 'tx_bytes', 'rx_counter', 'tx_counter', 'sampled_at',
    ];

    protected $casts = [
        'router_id'  => 'integer',
        'rx_bytes'   => 'integer',
        'tx_bytes'   => 'integer',
        'rx_counter' => 'integer',
        'tx_counter' => 'integer',
        'sampled_at' => 'datetime',
    ];
}
