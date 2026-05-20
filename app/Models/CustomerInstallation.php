<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInstallation extends Model
{
    protected $table = 'customer_installations';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'scheduled_date',
        'technician',
        'address',
        'equipment',
        'notes',
        'status',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_at'   => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
