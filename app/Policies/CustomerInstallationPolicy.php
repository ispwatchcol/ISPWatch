<?php

namespace App\Policies;

use App\Constants\Permissions;
use App\Models\CustomerInstallation;
use App\Models\User;

class CustomerInstallationPolicy
{
    /**
     * Any authenticated user in the same tenant can view an installation.
     * Tenant isolation is already enforced by resolveInstallation(); this
     * method serves as a formal policy layer for Gate::authorize() calls.
     */
    public function view(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id;
    }

    /**
     * Any authenticated user in the same tenant can update technical/agenda fields.
     */
    public function update(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id;
    }

    /**
     * Only admin/staff/accounting (role_id=1 or edit_discount permission) can
     * see financial/cartera fields. Technicians and clients are excluded.
     */
    public function viewFinancialData(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id
            && $this->hasFinancialAccess($user);
    }

    /**
     * Only admin/staff/accounting can write financial/cartera fields.
     * The route is already gated by permission:edit_discount middleware;
     * this policy provides a controller-level belt-and-suspenders check.
     */
    public function updateFinancialData(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id
            && $this->hasFinancialAccess($user);
    }

    private function hasFinancialAccess(User $user): bool
    {
        // role_id=1 (Administrador) bypasses all permission checks — mirrors CheckPermission middleware.
        if ((int) $user->role_id === 1) {
            return true;
        }

        $user->loadMissing('role');

        return $user->role?->hasPermission(Permissions::EDIT_DISCOUNT) ?? false;
    }
}
