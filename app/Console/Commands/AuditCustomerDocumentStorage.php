<?php

namespace App\Console\Commands;

use App\Models\CustomerDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ¿Qué documentos de cliente ya no tienen archivo detrás? (P-9 / KAN-62)
 *
 * Hasta el 29-jul-2026 (828865c) los documentos se escribían en el disco
 * `public`, que en App Platform es efímero: en cada despliegue los bytes se
 * iban y la fila quedaba. La convención de `file_path` no cambió al pasar a S3,
 * así que una fila perdida y una buena se ven idénticas. El enlace de la
 * perdida devuelve un error del proveedor, y cada caso llega a soporte como un
 * bug nuevo.
 *
 * Este comando SÓLO LEE. Recorre `customer_documents`, pregunta a S3 si existe
 * cada `file_path` y reporta tres cosas por separado:
 *
 *   - encontrados;
 *   - perdidos: S3 respondió que no existe;
 *   - error: no se pudo preguntar. Se cuenta aparte A PROPÓSITO. Con el
 *     almacenamiento caído, todo aparecería como «perdido», y eso es justo
 *     la confusión que se quiere evitar.
 *
 * Qué hacer con los perdidos (purgar, marcar o avisar) es una decisión aparte:
 * aquí no se toca ninguna fila.
 */
class AuditCustomerDocumentStorage extends Command
{
    /** Fecha del paso a S3 (828865c): lo anterior pudo perderse con el contenedor. */
    public const S3_CUTOVER = '2026-07-29';

    protected $signature = 'documents:audit-storage
                            {--tenant= : Sólo un tenant}
                            {--show=50 : Cuántos documentos perdidos listar (0 = ninguno)}';

    protected $description = 'Lista los documentos de cliente cuyo archivo ya no existe en S3 (sólo lectura)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $show     = max(0, (int) $this->option('show'));
        $disk     = Storage::disk('s3');

        $stats   = ['total' => 0, 'found' => 0, 'missing' => 0, 'missing_pre_s3' => 0, 'error' => 0];
        $missing = [];

        CustomerDocument::withoutGlobalScopes()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')
            ->select(['id', 'tenant_id', 'customer_id', 'type', 'file_path', 'created_at'])
            ->chunkById(200, function ($documents) use ($disk, &$stats, &$missing, $show) {
                foreach ($documents as $document) {
                    $stats['total']++;

                    try {
                        $exists = $document->file_path !== null
                            && $document->file_path !== ''
                            && $disk->exists($document->file_path);
                    } catch (Throwable $e) {
                        $stats['error']++;
                        continue;
                    }

                    if ($exists) {
                        $stats['found']++;
                        continue;
                    }

                    $stats['missing']++;
                    $preS3 = $document->created_at !== null
                        && $document->created_at->lt(self::S3_CUTOVER);
                    if ($preS3) {
                        $stats['missing_pre_s3']++;
                    }

                    if (count($missing) < $show) {
                        $missing[] = [
                            $document->id,
                            $document->tenant_id,
                            $document->customer_id,
                            $document->type,
                            $document->created_at?->toDateString() ?? '—',
                            $preS3 ? 'sí' : 'NO',
                        ];
                    }
                }
            });

        $this->table(['Métrica', 'Cantidad'], [
            ['Documentos revisados',                          $stats['total']],
            ['Con archivo en S3',                             $stats['found']],
            ['Sin archivo',                                   $stats['missing']],
            ['  de ellos, anteriores al ' . self::S3_CUTOVER, $stats['missing_pre_s3']],
            ['No se pudo consultar (error)',                  $stats['error']],
        ]);

        if ($missing !== []) {
            $this->table(['id', 'tenant', 'cliente', 'tipo', 'creado', '¿antes de S3?'], $missing);
        }

        if ($stats['error'] > 0) {
            $this->warn("{$stats['error']} documento(s) no se pudieron consultar: el resultado NO es concluyente. Revisa la conexión a S3 y vuelve a correrlo.");
        }

        $postCutover = $stats['missing'] - $stats['missing_pre_s3'];
        if ($postCutover > 0) {
            $this->warn("{$postCutover} documento(s) creados DESPUÉS del paso a S3 no tienen archivo: eso no lo explica la migración y hay que investigarlo aparte.");
        }

        return Command::SUCCESS;
    }
}
