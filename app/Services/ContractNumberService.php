<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * Arma el consecutivo visible. El prefijo es LIBRE: el ISP escribe lo que
     * quiera («CNO/», «Contrato N° », «FIBRA_2026.») y se respeta tal cual.
     * Lo que se sanea es el nombre del archivo (ver fileName()), no el número
     * — así el papel dice lo que el cliente quiere y S3 sigue recibiendo una
     * ruta segura.
     *
     * El separador «-» se añade sólo si el prefijo termina en carácter
     * alfanumérico. Quien escribe «CNO/» ya puso su propio separador y no
     * querría «CNO/-00001».
     */
    public static function format(?string $prefix, int $number): string
    {
        // Sólo se descarta el espacio a la izquierda (siempre accidental) y
        // los caracteres de control. El espacio final SÍ se conserva: en
        // «Contrato N° » es el separador que eligió el ISP.
        $clean = preg_replace('/\p{C}/u', '', (string) $prefix) ?? '';
        $clean = ltrim($clean);

        if (trim($clean) === '') {
            $clean = self::DEFAULT_PREFIX;
        }

        $padded = str_pad((string) $number, self::PAD, '0', STR_PAD_LEFT);
        $separator = preg_match('/[\p{L}\p{N}]$/u', $clean) ? '-' : '';

        return $clean . $separator . $padded;
    }

    /**
     * Nombre de archivo seguro derivado del consecutivo. El prefijo es libre y
     * puede traer «/», «°», acentos o espacios: ninguno puede acabar en una
     * clave de S3 tal cual (la barra crearía una carpeta fantasma).
     *
     * La parte numérica siempre sobrevive, así que dos contratos del mismo
     * cliente nunca colisionan aunque sus prefijos se saneen al mismo texto.
     */
    public static function fileName(string $contractNumber): string
    {
        // Str::ascii() usa el mapa de caracteres propio de Laravel
        // (voku/portable-ascii): no depende de la extensión intl ni de iconv,
        // así que da EL MISMO resultado en el Windows del desarrollador y en
        // el Linux del contenedor. Con iconv, «CÓRDOBA» salía «C-ORDOBA»
        // (transliteraba a «'O» y la comilla se convertía en guion).
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($contractNumber)) ?? '';
        $slug = trim($slug, '-._');

        if ($slug === '') {
            $slug = 'contrato';
        }

        return 'contrato_' . $slug . '.pdf';
    }
}
