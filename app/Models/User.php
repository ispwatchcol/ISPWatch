<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'user';
    protected $fillable = [
        'tenant_id',
        'role_id',
        'sectorial_id',
        'user_name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    public function tenant () 
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role ()
    {
        return $this->belongsTo(Role::class);
    }

    public function sectorial ()
    {
        return $this->belongsTo(Sectorial::class);
    }

    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class, 'user_id');
    }

    public function customerProfile()
    {
        return $this->hasOne(CustomerProfile::class, 'user_id');
    }
}
