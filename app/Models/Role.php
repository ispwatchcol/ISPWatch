<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Role extends Model
{
    protected $table = 'role';
    protected $fillable = [
        'name',
        'code',
        'permissions',
        'tenant_id',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function ($builder) {
            if (auth()->check() && auth()->user()->tenant_id) {
                $tenantId = auth()->user()->tenant_id;
                // Include tenant-specific roles AND global roles (tenant_id=NULL)
                $builder->where(function ($q) use ($tenantId) {
                    $q->where('role.tenant_id', $tenantId)
                      ->orWhereNull('role.tenant_id');
                });
            }
        });
    }

    /**
     * Returns all role IDs matching a given name for a tenant.
     * Prefers tenant-specific over global (tenant_id=NULL) fallback.
     */
    public static function idsByName(string $name, int $tenantId): array
    {
        return DB::table('role')
            ->where('name', $name)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Returns the best single role ID for a name in a tenant.
     * Picks tenant-specific over global. Returns null if not found.
     */
    public static function idByName(string $name, int $tenantId): ?int
    {
        return DB::table('role')
            ->where('name', $name)
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderByRaw('(tenant_id IS NULL)') // non-null (tenant-specific) first
            ->value('id');
    }

    /**
     * Check if role has specific permission
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }

        if (in_array('*', $this->permissions)) {
            return true;
        }

        return in_array($permission, $this->permissions);
    }
}
