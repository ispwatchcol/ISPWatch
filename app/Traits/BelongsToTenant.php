<?php

namespace App\Traits;

/**
 * BelongsToTenant Trait
 *
 * Automatically scopes model queries by tenant_id from the request.
 * Also auto-sets tenant_id when creating new records.
 *
 * Usage: Add `use BelongsToTenant;` to any model that has a tenant_id column.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait: add global scope and creating hook.
     */
    protected static function bootBelongsToTenant(): void
    {
        // Global scope: automatically filter by tenant_id on all queries
        static::addGlobalScope('tenant', function ($query) {
            $tenantId = request()->query('tenant') ?? request()->query('tenant_id');

            if ($tenantId) {
                $query->where((new static)->getTable() . '.tenant_id', $tenantId);
            }
        });

        // Auto-set tenant_id on creation if not already set
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $tenantId = request()->query('tenant') ?? request()->query('tenant_id');
                if ($tenantId) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * Scope to query without tenant filter (e.g., for admin operations).
     */
    public function scopeWithoutTenantScope($query)
    {
        return $query->withoutGlobalScope('tenant');
    }
}
