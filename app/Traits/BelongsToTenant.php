<?php

namespace App\Traits;

/**
 * BelongsToTenant Trait
 *
 * Automatically scopes model queries by tenant_id from the authenticated user.
 * Also auto-sets tenant_id when creating new records.
 *
 * SECURITY FIX (OWASP A01/A04): tenant_id is derived ONLY from the
 * authenticated user (auth()->user()), NEVER from request query params.
 * This prevents cross-tenant data leakage.
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
        // Global scope: automatically filter by tenant_id from authenticated user
        static::addGlobalScope('tenant', function ($query) {
            // SECURITY: Always derive tenant_id from the authenticated user.
            // Fall back to query param ONLY for backward compatibility during
            // the transition period (e.g., unauthenticated contexts like jobs).
            $tenantId = null;

            if (auth()->check()) {
                $tenantId = auth()->user()->tenant_id;
            }

            // Fallback: support console commands / queue jobs that may not
            // have an auth context but pass tenant explicitly.
            if (!$tenantId && app()->runningInConsole()) {
                $tenantId = request()->query('tenant') ?? request()->query('tenant_id');
            }

            if ($tenantId) {
                $query->where((new static)->getTable() . '.tenant_id', $tenantId);
            }
        });

        // Auto-set tenant_id on creation from the authenticated user
        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                if (auth()->check() && auth()->user()->tenant_id) {
                    $model->tenant_id = auth()->user()->tenant_id;
                }
            }
        });
    }

    /**
     * Scope to query without tenant filter (e.g., for admin operations or jobs).
     */
    public function scopeWithoutTenantScope($query)
    {
        return $query->withoutGlobalScope('tenant');
    }
}

