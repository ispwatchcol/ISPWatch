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

    /**
     * Eliminar FÍSICAMENTE un cliente y todo lo que cuelga de él.
     *
     * Permiso propio y no `edit_internet_service`, que es el que lo autorizaba
     * hasta ahora. Editar el servicio de un cliente y destruir su expediente
     * completo no son la misma potestad: con el permiso de edición podían
     * borrar clientes los roles Tecnico, Staff y Contabilidad de todos los ISP
     * —20 roles en total—, sin que ninguno de ellos tenga autoridad
     * administrativa.
     *
     * La operación sigue borrando facturas y pagos en cascada (deuda **P-43**,
     * sin resolver). Mientras eso siga así, el permiso debe ser lo más estrecho
     * posible.
     */
    const DELETE_CUSTOMERS = 'delete_customers';

    // Instalaciones permissions
    /**
     * Ver la cartera de una orden de instalación: valor de instalación,
     * adicionales, descuento, forma de pago, abono recibido y saldo.
     *
     * POR QUÉ HACE FALTA UN PERMISO PROPIO
     *
     * Hasta ahora ese bloque —«Información de Cartera» en el detalle de la
     * orden— estaba gobernado por `edit_discount`, un permiso cuya etiqueta
     * decía «Editar Descuento» y que en la práctica es lo único que hoy
     * gobierna. Quien administraba roles no tenía forma de adivinar que la
     * casilla del descuento era la que mostraba el valor de la instalación, y
     * el rol Técnico —que no la trae— no veía el apartado ni tenía casilla que
     * marcar para verlo.
     *
     * LEER NO ES ESCRIBIR
     *
     * Éste es un permiso de LECTURA. Con él la orden muestra el resumen de
     * cartera en modo consulta; los campos siguen siendo de sólo lectura y
     * `PUT /installations/{id}/billing` sigue exigiendo `edit_discount`. Un
     * técnico de campo necesita saber cuánto cobrar, que no es lo mismo que
     * poder cambiar el precio, aplicar un descuento o dar por recibido un
     * dinero que no entró.
     *
     * NO SE CONCEDE DE FÁBRICA AL ROL TÉCNICO
     *
     * Qué ve un técnico es una decisión de cada ISP, no del producto. La
     * migración de relleno sólo se lo da a los roles que ya podían verlo
     * (`edit_discount`) y a los `code = 'admin'`, para que el catálogo del
     * administrador quede completo. Al rol Técnico se lo concede a mano quien
     * administre los roles de su empresa.
     */
    const VIEW_INSTALLATION_COST = 'view_installation_cost';

    // Infraestructura permissions
    const MANAGE_ROUTERS = 'manage_routers';
    const VIEW_PLANS = 'view_plans';
    const VIEW_SECTORIALS = 'view_sectorials';

    // Inventario permissions
    const VIEW_INVENTORY = 'view_inventory';

    /**
     * Eliminar equipos, stock, proveedores y sucursales del inventario.
     *
     * Permiso propio y no `view_inventory`, que es el que lo autorizaba hasta
     * ahora: un permiso de LECTURA abría los cuatro `destroy` del grupo de
     * rutas de inventario.
     *
     * El fallo era preexistente pero inalcanzable —ninguna pantalla exponía el
     * borrado de equipos—. KAN-98 añadió el botón Eliminar en la tarjeta de
     * equipo, y con él borrar pasó a estar a un clic de cualquiera que pudiera
     * ver el inventario, el rol `Staff` incluido.
     *
     * Mismo tratamiento que `DELETE_CUSTOMERS`: se concede sólo a los roles con
     * `code = 'admin'`, y no por arrastre desde `view_inventory` — retirar la
     * capacidad es el objetivo, no un efecto colateral.
     *
     * NO cubre `store` ni `update`, que siguen bajo `view_inventory`. Es la
     * misma clase de defecto y queda anotado como deuda en
     * `docs/MEJORAS_RECOMENDADAS.md`; borrar es lo irreversible y es lo que
     * esta tarjeta cierra.
     */
    const DELETE_INVENTORY = 'delete_inventory';

    // Soporte permissions
    const VIEW_SUPPORT = 'view_support';

    /**
     * PR B · Capacidades separadas de la operación de tickets.
     *
     * `view_support` era un permiso-paraguas: quien lo tenía podía listar,
     * crear, editar, diagnosticar, adjuntar, ver evidencia y leer el historial.
     * Y además gobierna —todavía— instalaciones, sectoriales e inventario, así
     * que no se puede simplemente retirar.
     *
     * TRANSICIÓN, NO SUSTITUCIÓN. `view_support` se conserva y se sigue usando
     * donde gobierna otros módulos. Lo que cambia es que las rutas de TICKETS
     * pasan a exigir la capacidad concreta, y una migración reparte a cada rol
     * exactamente las que ya podía ejercer — ni una más.
     *
     * LOS ROLES DEFINITIVOS NO SE CONFIGURAN AQUÍ. La matriz de la sección 18
     * del requerimiento (Recepción/N1, N2, Técnico de campo, Supervisor,
     * Auditor) está pendiente de confirmación del cliente: es la decisión
     * **D-09**. Estos permisos son la herramienta; el reparto final es otra
     * conversación.
     */
    const TICKET_VIEW            = 'ticket_view';
    const TICKET_CREATE          = 'ticket_create';
    const TICKET_EDIT            = 'ticket_edit';
    const TICKET_ASSIGN          = 'ticket_assign';
    const TICKET_SET_PRIORITY    = 'ticket_set_priority';
    const TICKET_SET_CATEGORY    = 'ticket_set_category';
    const TICKET_DIAGNOSE        = 'ticket_diagnose';
    const TICKET_CONFIRM_CAUSE   = 'ticket_confirm_cause';
    const TICKET_NOTE            = 'ticket_note';
    const TICKET_ATTACH          = 'ticket_attach';
    const TICKET_VIEW_EVIDENCE   = 'ticket_view_evidence';
    const TICKET_VIEW_HISTORY    = 'ticket_view_history';
    const TICKET_TRANSITION      = 'ticket_transition';
    const TICKET_CLOSE           = 'ticket_close';
    const TICKET_CLOSE_OVERRIDE  = 'ticket_close_override';
    const TICKET_REOPEN          = 'ticket_reopen';
    const TICKET_ARCHIVE         = 'ticket_archive';
    const TICKET_RESTORE         = 'ticket_restore';
    const TICKET_MANAGE_CATALOGS = 'ticket_manage_catalogs';
    const TICKET_EXPORT          = 'ticket_export';

    /**
     * Los que todavía NO gobiernan ninguna acción del sistema.
     *
     * Se declaran para que la matriz quede completa y el cliente pueda repartir
     * roles sobre ella, pero hoy no hay endpoint que los exija: archivar y
     * restaurar son el PR C —y dependen de **D-10**—, reabrir no existe
     * (**D-12**), el cierre con excepción llega con las reglas de cierre del
     * PR #4, y no hay pantalla de administración de catálogos (**D-13**).
     *
     * La migración de transición NO los concede a nadie: dar una capacidad que
     * antes no se tenía sería justo lo contrario de una transición compatible.
     */
    public const TICKET_SIN_ACCION_TODAVIA = [
        self::TICKET_CLOSE_OVERRIDE,
        self::TICKET_REOPEN,
        self::TICKET_ARCHIVE,
        self::TICKET_RESTORE,
        self::TICKET_MANAGE_CATALOGS,
    ];

    // Facturación permissions
    const VIEW_BILLING = 'view_billing';

    // Sistema / Administración permissions
    const VIEW_STAFF = 'view_staff';
    const MANAGE_ROLES = 'manage_roles';
    const MANAGE_TENANT = 'manage_tenant';
    const MANAGE_DOCUMENT_TEMPLATES = 'manage_document_templates';
    const MANAGE_API_KEYS = 'manage_api_keys';

    /**
     * Emitir llaves de la API para el PROPIO tenant (auto-servicio).
     *
     * Distinto de MANAGE_API_KEYS, que es del tenant operador y alcanza a todos
     * los tenants. Éste sólo permite administrar las llaves de la empresa a la
     * que pertenece quien lo tiene, con los límites de config/api_keys.php.
     */
    const MANAGE_OWN_API_KEYS = 'manage_own_api_keys';
    const VIEW_SETTINGS = 'view_settings';
    const EXECUTE_MASS_ACTIONS = 'execute_mass_actions';
    const VIEW_AUDIT_LOG = 'view_audit_log';

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
                self::EDIT_DISCOUNT => 'Editar Descuento y Cartera de Instalación',
                self::ACTIVATE_DEACTIVATE_CLIENTS => 'Activar y Desactivar Clientes',
                self::EDIT_PENDING_BALANCE => 'Editar Saldo Pendiente',
                self::VIEW_CLIENTS => 'Lista de Clientes',
                self::EDIT_INTERNET_SERVICE => 'Editar Servicio Internet',
                self::VIEW_CLIENT_TRAFFIC => 'Tráfico Clientes',
                self::ADD_CLIENTS => 'Agregar Clientes',
                self::DELETE_CUSTOMERS => 'Eliminar Clientes (destructivo)',
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
            'Infraestructura' => [
                self::MANAGE_ROUTERS => 'Gestionar Routers',
                self::VIEW_PLANS => 'Ver Planes de Internet',
                self::VIEW_SECTORIALS => 'Ver Sectoriales',
            ],
            'Inventario' => [
                self::VIEW_INVENTORY => 'Ver Inventario',
                self::DELETE_INVENTORY => 'Eliminar de Inventario (equipos, stock, proveedores, sucursales)',
            ],
            'Instalaciones' => [
                self::VIEW_INSTALLATION_COST => 'Ver Costo de Instalación (valor, abonos y saldo)',
                self::DELETE_INSTALLATIONS => 'Eliminar Instalaciones',
            ],
            'Soporte' => [
                self::TICKET_VIEW => 'Tickets · ver listado y detalle',
                self::TICKET_CREATE => 'Tickets · crear',
                self::TICKET_EDIT => 'Tickets · editar contenido',
                self::TICKET_ASSIGN => 'Tickets · asignar o reasignar técnico',
                self::TICKET_SET_PRIORITY => 'Tickets · cambiar prioridad',
                self::TICKET_SET_CATEGORY => 'Tickets · cambiar categoría',
                self::TICKET_DIAGNOSE => 'Tickets · registrar diagnóstico',
                self::TICKET_CONFIRM_CAUSE => 'Tickets · confirmar causa',
                self::TICKET_NOTE => 'Tickets · agregar notas',
                self::TICKET_ATTACH => 'Tickets · adjuntar evidencia',
                self::TICKET_VIEW_EVIDENCE => 'Tickets · ver y descargar evidencia',
                self::TICKET_VIEW_HISTORY => 'Tickets · ver historial',
                self::TICKET_TRANSITION => 'Tickets · cambiar estado',
                self::TICKET_CLOSE => 'Tickets · cerrar',
                self::TICKET_CLOSE_OVERRIDE => 'Tickets · cerrar con excepción (aún sin uso)',
                self::TICKET_REOPEN => 'Tickets · reabrir (aún sin uso)',
                self::TICKET_ARCHIVE => 'Tickets · archivar (aún sin uso)',
                self::TICKET_RESTORE => 'Tickets · restaurar (aún sin uso)',
                self::TICKET_MANAGE_CATALOGS => 'Tickets · administrar catálogos (aún sin uso)',
                self::TICKET_EXPORT => 'Tickets · métricas y exportación',
                self::VIEW_SUPPORT => 'Ver Soporte Técnico',
            ],
            'Facturación' => [
                self::VIEW_BILLING => 'Ver Facturación',
            ],
            'Sistema' => [
                self::VIEW_STAFF => 'Ver Personal',
                self::MANAGE_ROLES => 'Gestionar Roles',
                self::MANAGE_TENANT => 'Gestionar Configuración de Empresa',
                self::MANAGE_DOCUMENT_TEMPLATES => 'Gestionar Plantillas de Documentos (factura, contrato, instalación)',
                self::MANAGE_API_KEYS => 'Gestionar Llaves de API (integraciones externas)',
                self::MANAGE_OWN_API_KEYS => 'Emitir Llaves de API propias de la empresa (auto-servicio)',
                self::VIEW_SETTINGS => 'Ver Ajustes del Sistema',
                self::EXECUTE_MASS_ACTIONS => 'Ejecutar Acciones Masivas',
                self::VIEW_AUDIT_LOG => 'Ver Bitácora de Auditoría (cambios de precio, planes, pagos y saldos)',
            ],
        ];
    }

    public static function getPermissionsByRole(string $role): array
    {
        $allPerms = self::getAllPermissions();
        $allPermissions = [];
        foreach ($allPerms as $group) {
            $allPermissions = array_merge($allPermissions, array_keys($group));
        }

        return match ($role) {
            'admin', 'Administrador' => $allPermissions,
            'technician', 'Técnico' => [
                self::ACTIVATE_DEACTIVATE_CLIENTS,
                self::DELETE_INSTALLATIONS,
                self::EDIT_PENDING_BALANCE,
                self::VIEW_CLIENTS,
                self::EDIT_INTERNET_SERVICE,
                self::VIEW_CLIENT_TRAFFIC,
                self::ADD_CLIENTS,
            ],
            'accounting', 'Contabilidad' => [
                self::EDIT_DISCOUNT,
                self::EDIT_PENDING_BALANCE,
                self::EDIT_INTERNET_SERVICE,
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
            'staff', 'Staff' => [
                self::VIEW_CLIENTS,
                self::ADD_CLIENTS,
                self::EDIT_DISCOUNT,
                self::ACTIVATE_DEACTIVATE_CLIENTS,
                self::EDIT_PENDING_BALANCE,
                self::EDIT_INTERNET_SERVICE,
                self::VIEW_CLIENT_TRAFFIC,
                self::VIEW_PLANS,
                self::VIEW_SECTORIALS,
                self::VIEW_INVENTORY,
                self::VIEW_SUPPORT,
                self::VIEW_BILLING,
                self::VIEW_DASHBOARD_STATS,
                self::SEARCH_INVOICES,
                self::REGISTER_PAYMENTS,
                self::MANAGE_PAYMENT_PROMISES,
                self::VIEW_INVOICES,
            ],
            'client', 'Cliente' => [],
            default => [],
        };
    }

    public static function getPermissionsByRoleFlat(string $role): string
    {
        $groupedPerms = self::getAllPermissions();
        $permissionLabels = [];

        foreach ($groupedPerms as $group) {
            foreach ($group as $key => $label) {
                $permissionLabels[$key] = $label;
            }
        }

        $rolePerms = self::getPermissionsByRole($role);
        $result = [];

        foreach ($rolePerms as $perm) {
            if (isset($permissionLabels[$perm])) {
                $result[] = $permissionLabels[$perm];
            }
        }

        return json_encode($result);
    }
}
