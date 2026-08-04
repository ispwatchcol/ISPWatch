<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Reserva el consecutivo de los contratos de servicio.
 *
 * Mismo mecanismo que el de facturas (BillingService::generateInvoiceNumber):
 * el contador vive en la fila del tenant y se reserva dentro de una
 * transacción con lockForUpdate, así dos firmas simultáneas no obtienen el
 * mismo número. El unique (tenant_id, contract_number) es la red de seguridad.
 */
class ContractNumberService
{
    /** Prefijo usado cuando el ISP no configuró uno propio. */
    public const DEFAULT_PREFIX = 'CTR';

    /** Dígitos del consecutivo: CTR-00001. */
    public const PAD = 5;

    /**
     * Reserva y devuelve el siguiente consecutivo del tenant.
     */
    public function allocate(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->first();

            if (!$tenant) {
                throw new \RuntimeException("Tenant no encontrado: {$tenantId}");
            }

            $next = (int) ($tenant->next_contract_number ?: 1);
            $number = self::format($tenant->contract_prefix, $next);

            $tenant->next_contract_number = $next + 1;
            $tenant->save();

            return $number;
        });
    }

    /**
     * Arma el consecutivo visible. El prefijo se limita a lo que es seguro
     * dentro de un nombre de archivo y de una ruta de S3.
     */
    public static function format(?string $prefix, int $number): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $prefix);
        $clean = trim((string) $clean, '-');

        if ($clean === '') {
            $clean = self::DEFAULT_PREFIX;
        }

        return $clean . '-' . str_pad((string) $number, self::PAD, '0', STR_PAD_LEFT);
    }
}
