<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserService extends Model
{
    protected $table = 'user_services';

    protected $fillable = [
        'user_id',
        'service_plan_id',
        'start_date',
        'end_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function servicePlan()
    {
        return $this->belongsTo(Plan::class, 'service_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
