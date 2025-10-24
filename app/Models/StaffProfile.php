<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffProfile extends Model
{
    protected $table = 'staff_profile';
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
