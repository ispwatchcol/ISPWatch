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
use Illuminate\Support\Str;

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
        $token = Str::random(64);
        $this->updateQuietly(['email_verification_token' => $token]);
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
        'permissions',
        'email_verification_token',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'status' => 'boolean',
        'last_access' => 'datetime',
        'deleted_at' => 'datetime',
        'permissions' => 'array',
    ];

    protected static function booted(): void
    {
        // Keep users.name derived from user_name + user_lastname so the legacy
        // `name` column never diverges from the split first/last name fields.
        // Reason: three name columns (name, user_name, user_lastname) were drifting
        // because update flows touched only some of them — see CustomerProfileController.update.
        static::saving(function (self $user) {
            $first = trim((string) ($user->user_name ?? ''));
            $last  = trim((string) ($user->user_lastname ?? ''));
            $derived = trim($first . ' ' . $last);
            if ($derived !== '') {
                $user->name = $derived;
            }
        });
    }

    /**
     * Build an ASCII-safe login/contact email from a name or a typed email.
     * Transliterates accents and ñ (José→jose, Muñoz→munoz), lowercases,
     * strips whitespace and any character that isn't valid in an email
     * (keeps letters, digits and . _ + - @). The NAME columns (name,
     * user_name, user_lastname) keep their accents/ñ; only the email is
     * normalized — per the network rule that login/tenant emails must never
     * carry ñ or tildes, whether created manually or via bulk import.
     */
    public static function sanitizeEmail(?string $value): string
    {
        $ascii = strtolower(Str::ascii((string) $value)); // ñ→n, á→a, ü→u …
        $ascii = preg_replace('/\s+/', '', $ascii);        // drop whitespace
        return preg_replace('/[^a-z0-9._+\-@]/', '', $ascii);
    }

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
