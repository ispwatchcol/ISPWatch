<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInstallation extends Model
{
    protected $table = 'customer_installations';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'prospect_id',
        'scheduled_date',
        'technician',
        'technician_id',
        'address',
        'equipment',
        'notes',
        'sheet',
        'customer_signature_path',
        'technician_signature_path',
        'signed_at',
        'status',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_at'   => 'datetime',
        'signed_at'      => 'datetime',
        'sheet'          => 'array',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function technicianUser()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class, 'installation_id');
    }
}
