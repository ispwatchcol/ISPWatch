<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\CustomVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, MustVerifyEmailTrait;

    /**
     * Send the email verification notification (custom ISPWatch template).
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail());
    }

    protected $table = 'users';
    protected $fillable = [
        'name',
        'tenant_id',
        'role_id',
        'sectorial_id',
        'user_name',
        'user_lastname',
        'email',
        'email_tenant',
        'tel',
        'password',
        'status',
        'last_access',
        'deleted_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'status' => 'boolean',
        'last_access' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function sectorial()
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

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'customer_id');
    }

    public function userServices()
    {
        return $this->hasMany(UserService::class, 'user_id');
    }
}
