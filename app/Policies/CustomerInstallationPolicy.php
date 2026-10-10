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
     * Leer la cartera de la orden: valor, adicionales, descuento, abono, saldo.
     *
     * La abren dos permisos. `edit_discount` es el histórico y se conserva.
     * `view_installation_cost` es el de lectura pura, pensado para el técnico
     * de campo que necesita saber cuánto cobrar sin poder alterar la cifra.
     */
    public function viewFinancialData(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id
            && $this->hasFinancialReadAccess($user);
    }

    /**
     * Escribir la cartera: sigue siendo sólo `edit_discount`.
     *
     * Guardar recalcula la factura de instalación y da por recibido el abono,
     * así que el permiso de lectura NO alcanza. La ruta ya exige
     * `permission:edit_discount`; esto es el segundo cerrojo.
     */
    public function updateFinancialData(User $user, CustomerInstallation $installation): bool
    {
        return (int) $user->tenant_id === (int) $installation->tenant_id
            && $this->hasFinancialWriteAccess($user);
    }

    private function hasFinancialReadAccess(User $user): bool
    {
        if ($this->hasFinancialWriteAccess($user)) {
            return true;
        }

        $user->loadMissing('role');

        return $user->role?->hasPermission(Permissions::VIEW_INSTALLATION_COST) ?? false;
    }

    private function hasFinancialWriteAccess(User $user): bool
    {
        // role_id=1 (Administrador) bypasses all permission checks — mirrors CheckPermission middleware.
        if ((int) $user->role_id === 1) {
            return true;
        }

        $user->loadMissing('role');

        return $user->role?->hasPermission(Permissions::EDIT_DISCOUNT) ?? false;
    }
}
