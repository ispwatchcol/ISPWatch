<?php

namespace App\Constants;

class Permissions
{
    // Cliente permissions
    const EDIT_DISCOUNT = 'edit_discount';
    const ACTIVATE_DEACTIVATE_CLIENTS = 'activate_deactivate_clients';
    const DELETE_INSTALLATIONS = 'delete_installations';
    const EDIT_PENDING_BALANCE = 'edit_pending_balance';
    const VIEW_CLIENTS = 'view_clients';
    const EDIT_INTERNET_SERVICE = 'edit_internet_service';
    const VIEW_CLIENT_TRAFFIC = 'view_client_traffic';
    const ADD_CLIENTS = 'add_clients';

    // Facturas permissions
    const VIEW_DASHBOARD_STATS = 'view_dashboard_stats';
    const ADD_EXPENSE = 'add_expense';
    const SEARCH_INVOICES = 'search_invoices';
    const EDIT_TOTAL_TO_PAY = 'edit_total_to_pay';
    const REGISTER_PAYMENTS = 'register_payments';
    const DELETE_INVOICE = 'delete_invoice';
    const MANAGE_PAYMENT_PROMISES = 'manage_payment_promises';

    // Contabilidad permissions
    const EDIT_EXPENSE = 'edit_expense';
    const REGISTER_PAYMENT_OVER_3_DAYS = 'register_payment_over_3_days';
    const DELETE_TRANSFER = 'delete_transfer';
    const REGISTER_PAYMENTS_ACCOUNTING = 'register_payments_accounting';
    const EDIT_PAYMENT_DATE = 'edit_payment_date';
    const VIEW_EXPENSES = 'view_expenses';
    const VIEW_INVOICES = 'view_invoices';
    const ADD_TRANSFER = 'add_transfer';

    public static function getAllPermissions(): array
    {
        return [
            'Clientes' => [
                self::EDIT_DISCOUNT => 'Editar Descuento',
                self::ACTIVATE_DEACTIVATE_CLIENTS => 'Activar y Desactivar Clientes',
                self::DELETE_INSTALLATIONS => 'Eliminar Instalaciones',
                self::EDIT_PENDING_BALANCE => 'Editar Saldo Pendiente',
                self::VIEW_CLIENTS => 'Lista de Clientes',
                self::EDIT_INTERNET_SERVICE => 'Editar Servicio Internet',
                self::VIEW_CLIENT_TRAFFIC => 'Tráfico Clientes',
                self::ADD_CLIENTS => 'Agregar Clientes',
            ],
            'Facturas' => [
                self::VIEW_DASHBOARD_STATS => 'Dashboard / Estadísticas',
                self::ADD_EXPENSE => 'Agregar Gasto',
                self::SEARCH_INVOICES => 'Buscar Facturas',
                self::EDIT_TOTAL_TO_PAY => 'Editar Total a Pagar',
                self::REGISTER_PAYMENTS => 'Registrar Pagos',
                self::DELETE_INVOICE => 'Eliminar Factura',
                self::MANAGE_PAYMENT_PROMISES => 'Promesas de Pago',
            ],
            'Contabilidad' => [
                self::EDIT_EXPENSE => 'Editar Gasto',
                self::REGISTER_PAYMENT_OVER_3_DAYS => 'Registrar Pago Mayor 3 Días',
                self::DELETE_TRANSFER => 'Eliminar Transferencia',
                self::REGISTER_PAYMENTS_ACCOUNTING => 'Registrar Pagos',
                self::EDIT_PAYMENT_DATE => 'Editar Fecha de Pago',
                self::VIEW_EXPENSES => 'Lista de Gastos',
                self::VIEW_INVOICES => 'Lista de Facturas',
                self::ADD_TRANSFER => 'Agregar Transferencia',
            ],
        ];
    }

    public static function getPermissionsByRole(string $roleName): array
    {
        $allPerms = self::getAllPermissions();
        $allPermissions = [];
        foreach ($allPerms as $group) {
            $allPermissions = array_merge($allPermissions, array_keys($group));
        }

        return match ($roleName) {
            'Administrador' => $allPermissions,
            'Técnico' => [
                self::EDIT_DISCOUNT,
                self::EDIT_PENDING_BALANCE,
                self::VIEW_CLIENTS,
                self::VIEW_CLIENT_TRAFFIC,
                self::ADD_CLIENTS,
                self::VIEW_DASHBOARD_STATS,
                self::REGISTER_PAYMENTS,
                self::VIEW_EXPENSES,
                self::VIEW_INVOICES,
            ],
            'Contabilidad' => [
                self::VIEW_DASHBOARD_STATS,
                self::ADD_EXPENSE,
                self::REGISTER_PAYMENTS,
                self::DELETE_INVOICE,
                self::EDIT_EXPENSE,
                self::REGISTER_PAYMENT_OVER_3_DAYS,
                self::REGISTER_PAYMENTS_ACCOUNTING,
                self::EDIT_PAYMENT_DATE,
                self::VIEW_EXPENSES,
                self::VIEW_INVOICES,
                self::ADD_TRANSFER,
                self::DELETE_TRANSFER,
            ],
            default => [],
        };
    }

    public static function getPermissionsByRoleFlat(string $roleName): string
    {
        $groupedPerms = self::getAllPermissions();
        $permissionLabels = [];

        foreach ($groupedPerms as $group) {
            foreach ($group as $key => $label) {
                $permissionLabels[$key] = $label;
            }
        }

        $rolePerms = self::getPermissionsByRole($roleName);
        $result = [];

        foreach ($rolePerms as $perm) {
            if (isset($permissionLabels[$perm])) {
                $result[] = $permissionLabels[$perm];
            }
        }

        return json_encode($result);
    }
}
