<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $table = 'customer_profile';
    protected $fillable = [
        'user_id',
        'name',
        'last_name',
        'department',
        'position'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
