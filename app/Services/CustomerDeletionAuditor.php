<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deja constancia de una eliminación de cliente ANTES de que ocurra.
 *
 * POR QUÉ ES UNA CLASE Y NO CUATRO LÍNEAS EN EL CONTROLADOR
 *
 * Porque el requisito es que **si la auditoría no se puede escribir, no se
 * borre nada**, y eso hay que poder probarlo. Con la llamada estática metida en
 * el controlador, la única forma de simular el fallo era romper la tabla
 * (`DROP TABLE audit_logs`) — que en PostgreSQL deja la transacción de la
 * prueba abortada y hace que cualquier aserción posterior reviente con 25P02.
 *
 * Como colaborador inyectado, la prueba lo sustituye por uno que lanza y el
 * escenario queda idéntico en los dos motores, sin tocar el esquema.
 *
 * El comportamiento normal no cambia: escribe la misma fila que antes.
 *
 * QUÉ SE GUARDA Y QUÉ NO
 *
 * **Conteos, no contenido.** Cuántos documentos, servicios e instalaciones se
 * van a destruir, y cuántas facturas, pagos, créditos y tickets sobreviven. Del
 * cliente, sólo nombre y cédula — lo mínimo para saber de quién se habla en una
 * revisión y que ya figura en las facturas emitidas.
 *
 * Nunca contraseñas, tokens, datos de pago, documentos ni contenido de
 * adjuntos: copiarlos convertiría `audit_logs` en el sitio donde sobreviven
 * precisamente los datos que alguien pidió eliminar.
 */
class CustomerDeletionAuditor
{
    /**
     * Escribe el evento y devuelve el identificador de correlación.
     *
     * Se llama FUERA de la transacción del borrado a propósito. Si la
     * compartieran, un fallo al borrar revertiría también la constancia de que
     * se intentó — y un intento fallido de destruir el histórico de un abonado
     * es justo lo que hay que poder revisar después.
     *
     * @throws RuntimeException si la fila no queda persistida.
     */
    public function registrar(User $user, CustomerProfile $profile, string $reason): string
    {
        $correlacion = (string) Str::uuid();

        $log = AuditLog::log([
            'tenant_id'   => $user->tenant_id,
            'action'      => 'customer_deleted',
            'model_type'  => CustomerProfile::class,
            'model_id'    => (int) $user->id,
            'old_values'  => $this->resumen($user, $profile),
            'new_values'  => [
                'reason'         => $reason,
                'correlation_id' => $correlacion,
            ],
            'description' => "Eliminación física del cliente {$profile->name} {$profile->last_name}.",
        ]);

        if (!$log || !$log->exists) {
            throw new RuntimeException('El registro de auditoría no quedó persistido.');
        }

        return $correlacion;
    }

    /**
     * Alcance de lo que se va a destruir, en cifras.
     *
     * @return array<string, mixed>
     */
    private function resumen(User $user, CustomerProfile $profile): array
    {
        $id = (int) $user->id;

        $contar = function (string $tabla, string $columna) use ($id): int {
            try {
                return (int) DB::table($tabla)->where($columna, $id)->count();
            } catch (\Throwable) {
                // Una tabla ausente no puede impedir la auditoría.
                return -1;
            }
        };

        return [
            'customer' => [
                'user_id'   => $id,
                'name'      => trim("{$profile->name} {$profile->last_name}"),
                'cedula'    => $profile->cedula,
                'tenant_id' => (int) $user->tenant_id,
            ],
            // Lo que la cascada se llevará por delante.
            'se_eliminan' => [
                'customer_documents'     => $contar('customer_documents', 'customer_id'),
                'user_services'          => $contar('user_services', 'user_id'),
                'customer_installations' => $contar('customer_installations', 'customer_id'),
                'billing_action_logs'    => $contar('billing_action_logs', 'customer_id'),
                'suspension_action_logs' => $contar('suspension_action_logs', 'customer_id'),
            ],
            // Lo que SOBREVIVE, para que la revisión sepa dónde seguir mirando.
            //
            // Las cinco tablas de dinero se movieron aquí el 2026-09-09, cuando
            // P-43 pasó sus claves foráneas a `SET NULL`. Antes estaban en
            // `se_eliminan` y el conteo servía para saber cuánto se había
            // perdido; ahora sirve para saber cuánto hay que ir a buscar, y
            // dónde: las filas siguen en su tabla, con `customer_id` nulo y el
            // nombre del titular congelado en `customer_name`.
            'se_conservan' => [
                'invoices'                     => $contar('invoices', 'customer_id'),
                'payments'                     => $contar('payments', 'customer_id'),
                'invoice_carryovers'           => $contar('invoice_carryovers', 'customer_id'),
                'customer_credits'             => $contar('customer_credits', 'customer_id'),
                'customer_additional_services' => $contar('customer_additional_services', 'customer_id'),
                'support_ticket'               => $contar('support_ticket', 'user_id'),
                'support_ticket_message'       => $contar('support_ticket_message', 'user_id'),
                'support_ticket_attachment'    => $contar('support_ticket_attachment', 'user_id'),
            ],
        ];
    }
}
