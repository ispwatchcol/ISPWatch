<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Numera hacia atrás los contratos ya firmados.
 *
 * Se recorren por tenant en orden cronológico de firma, así el consecutivo
 * refleja el orden real en que se celebraron. El PDF viejo NO se regenera —
 * ya está firmado y almacenado en S3— así que el número queda solo en la
 * ficha del cliente; de ahí en adelante sí va impreso dentro del documento.
 *
 * Solo se numeran los contratos generados por el sistema (signed = true).
 * Un PDF que el ISP subió a mano no se puede sellar por dentro, así que
 * ponerle consecutivo sería prometer una trazabilidad que el papel no tiene.
 *
 * La lógica de formato está duplicada a propósito respecto de
 * App\Services\ContractNumberService: una migración tiene que poder
 * re-ejecutarse aunque esa clase cambie o desaparezca.
 */
return new class extends Migration {
    private const DEFAULT_PREFIX = 'CTR';
    private const PAD = 5;

    public function up(): void
    {
        $tenantIds = DB::table('customer_documents')
            ->where('type', 'contrato')
            ->where('signed', true)
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            $prefix = $this->cleanPrefix(
                DB::table('tenant')->where('id', $tenantId)->value('contract_prefix')
            );

            $documents = DB::table('customer_documents')
                ->where('tenant_id', $tenantId)
                ->where('type', 'contrato')
                ->where('signed', true)
                ->whereNull('contract_number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $next = (int) (DB::table('tenant')->where('id', $tenantId)->value('next_contract_number') ?: 1);

            foreach ($documents as $documentId) {
                DB::table('customer_documents')
                    ->where('id', $documentId)
                    ->update([
                        'contract_number' => $prefix . '-' . str_pad((string) $next, self::PAD, '0', STR_PAD_LEFT),
                    ]);

                $next++;
            }

            DB::table('tenant')->where('id', $tenantId)->update(['next_contract_number' => $next]);
        }
    }

    public function down(): void
    {
        // El backfill es reversible: se limpian los números y cada tenant
        // vuelve a arrancar en 1.
        DB::table('customer_documents')
            ->where('type', 'contrato')
            ->update(['contract_number' => null]);

        DB::table('tenant')->update(['next_contract_number' => 1]);
    }

    private function cleanPrefix(?string $prefix): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) $prefix), '-');

        return $clean !== '' ? $clean : self::DEFAULT_PREFIX;
    }
};
