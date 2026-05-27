<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prospect extends Model
{
    protected $table = 'prospects';

    public const STATUSES = ['interesado', 'agendado', 'instalado', 'convertido', 'rechazado'];

    protected $fillable = [
        'tenant_id',
        'name',
        'last_name',
        'cedula',
        'email',
        'tel',
        'address',
        'city',
        'state',
        'notes',
        'status',
        'converted_user_id',
        'converted_at',
        'created_by',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
    ];

    protected $appends = ['full_name'];

    public function installations()
    {
        return $this->hasMany(CustomerInstallation::class, 'prospect_id');
    }

    public function convertedUser()
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
