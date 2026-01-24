<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuspensionActionLog extends Model
{
    protected $fillable = [
        'router_id',
        'customer_id',
        'ip',
        'action',
        'status',
        'error_message',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
