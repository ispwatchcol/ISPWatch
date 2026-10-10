<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los estados de la Solicitud Maestra, literales.
 *
 * FUENTE, SIN INTERPRETAR
 *
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`, sección
 * **7. Ciclo de vida requerido**. Los nueve del flujo salen del diagrama tal
 * como está escrito:
 *
 *   RADICADO → EN CLASIFICACIÓN → EN DIAGNÓSTICO REMOTO → ASIGNADO →
 *   VISITA PROGRAMADA (cuando aplique) → EN INTERVENCIÓN →
 *   SERVICIO RESTABLECIDO → EN OBSERVACIÓN (opcional) → CERRADO
 *
 * Y los nueve auxiliares del bloque «Estados auxiliares requeridos» que va
 * justo debajo, en el mismo orden del documento.
 *
 * Las ETIQUETAS son la transcripción en la caja del documento, pasadas a
 * mayúscula inicial. Los CÓDIGOS son su forma estable en snake_case sin
 * tildes: el documento no asigna código técnico a ningún estado —igual que no
 * se lo asignó a las subcausas del Anexo A— así que se derivan del nombre, que
 * es la única fuente que hay, y quedan documentados aquí para que se puedan
 * cotejar uno a uno.
 *
 * LOS CUATRO ESTADOS VIEJOS NO SE RENOMBRAN NI SE BORRAN
 *
 * `open`, `in_progress`, `resolved` y `closed` se quedan, marcados como
 * `legacy`. Renombrarlos habría roto dos cosas a la vez:
 *
 *   · El CONTRATO CONGELADO del integrador. La R2 dejó escrito que `status`
 *     sale como código en texto y que «el integrador compara contra 'open'»;
 *     devolverle `radicado` no le daría ningún error, simplemente dejaría de
 *     coincidir y sus tickets desaparecerían en silencio.
 *   · Los 27 tickets que ya existen en producción, que apuntan por clave
 *     foránea a esas cuatro filas.
 *
 * La compatibilidad se resuelve con `legacy_code`: cada estado nuevo declara a
 * cuál de los cuatro equivale. La API de socios y las estadísticas leen esa
 * columna, así que el contrato sigue diciendo `open` mientras el panel dice
 * «En clasificación».
 *
 * `is_initial` SÍ se mueve: los tickets nuevos nacen en `radicado`, que es lo
 * que pide el documento. Los viejos siguen donde están.
 *
 * NINGÚN TICKET CAMBIA DE ESTADO. Esta migración sólo añade vocabulario.
 */
return new class extends Migration
{
    private const TABLA = 'ticket_status';

    /**
     * Los nueve del flujo. § 7, diagrama.
     *
     * Pesos de 100 en adelante para no chocar con los 10/20/30/40 de los
     * legacy, y de diez en diez para poder intercalar sin renumerar.
     *
     * [code, label, weight, legacy_code, is_terminal, stamps_resolved_at, stamps_closed_at]
     */
    private const FLUJO = [
        ['radicado',              'Radicado',                100, 'open',        false, false, false],
        ['en_clasificacion',      'En clasificación',        110, 'open',        false, false, false],
        ['en_diagnostico_remoto', 'En diagnóstico remoto',   120, 'in_progress', false, false, false],
        ['asignado',              'Asignado',                130, 'in_progress', false, false, false],
        ['visita_programada',     'Visita programada',       140, 'in_progress', false, false, false],
        ['en_intervencion',       'En intervención',         150, 'in_progress', false, false, false],
        // «Servicio restablecido registra el momento en que vuelve la
        // conectividad»: es el que estampa `resolved_at`. NO es terminal —el
        // documento dedica un recuadro entero a que restablecido ≠ cerrado—.
        ['servicio_restablecido', 'Servicio restablecido',   160, 'resolved',    false, true,  false],
        ['en_observacion',        'En observación',          170, 'resolved',    false, false, false],
        ['cerrado',               'Cerrado',                 180, 'closed',      true,  false, true],
    ];

    /** Los nueve auxiliares. § 7, «Estados auxiliares requeridos». */
    private const AUXILIARES = [
        ['pendiente_cliente',          'Pendiente del cliente',       200, 'in_progress', false, false, false],
        ['pendiente_material',         'Pendiente de material',       210, 'in_progress', false, false, false],
        ['pendiente_tercero',          'Pendiente de tercero',        220, 'in_progress', false, false, false],
        ['pendiente_infraestructura',  'Pendiente de infraestructura',230, 'in_progress', false, false, false],
        ['asociado_incidente_masivo',  'Asociado a incidente masivo', 240, 'in_progress', false, false, false],
        // Terminal: un duplicado no se sigue trabajando. El documento lo lista
        // como auxiliar sin decir si cierra; se marca terminal porque la
        // alternativa —dejarlo abierto para siempre— ensucia toda métrica de
        // pendientes. Queda anotado como supuesto en el diseño.
        ['duplicado',                  'Duplicado',                   250, 'closed',      true,  false, false],
        ['no_fue_posible_contactar',   'No fue posible contactar',    260, 'in_progress', false, false, false],
        // «Solución temporal … debe generar seguimiento o autorización»
        // (§ 15.9): por eso NO es terminal.
        ['solucion_temporal',          'Solución temporal',           270, 'resolved',    false, false, false],
        ['reabierto',                  'Reabierto',                   280, 'in_progress', false, false, false],
    ];

    /** Equivalencia de los viejos consigo mismos, para que la columna no tenga huecos. */
    private const LEGACY = ['open', 'in_progress', 'resolved', 'closed'];

    public function up(): void
    {
        $this->columnas();
        $this->sembrar();
        $this->marcarLegacy();
        $this->moverEstadoInicial();

        // El integrador cachea por versión; añadir vocabulario la sube.
        DB::table('ticket_catalog_version')
            ->where('catalog', 'status')
            ->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /**
     * Retira el vocabulario nuevo y devuelve `is_initial` a `open`.
     *
     * NO BORRA una fila a la que algún ticket ya apunte: la clave foránea de
     * `support_ticket.status_id` es `RESTRICT` y reventaría, que es justo lo
     * que debe pasar. Revertir con tickets ya movidos al flujo nuevo exige
     * primero devolverlos a mano, deliberadamente.
     */
    public function down(): void
    {
        $codigos = array_merge(
            array_column(self::FLUJO, 0),
            array_column(self::AUXILIARES, 0),
        );

        $enUso = DB::table('support_ticket')
            ->join(self::TABLA . ' as ts', 'ts.id', '=', 'support_ticket.status_id')
            ->whereIn('ts.code', $codigos)
            ->exists();

        if ($enUso) {
            throw new RuntimeException(
                'Hay tickets en los estados del flujo nuevo. Revertir los borraría del catálogo '
                . 'y dejaría esos tickets sin estado. Muévelos antes, a mano y a conciencia.'
            );
        }

        DB::table(self::TABLA)->whereIn('code', $codigos)->delete();

        DB::table(self::TABLA)->where('code', 'open')->update(['is_initial' => true]);

        Schema::table(self::TABLA, function (Blueprint $table) {
            foreach (['legacy_code', 'flow_category'] as $columna) {
                if (Schema::hasColumn(self::TABLA, $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        DB::table('ticket_catalog_version')
            ->where('catalog', 'status')
            ->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    private function columnas(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLA, 'flow_category')) {
                // `main` = los nueve del diagrama · `auxiliary` = los nueve de
                // abajo · `legacy` = los cuatro de antes de la Solicitud.
                // Default `legacy` para que las filas que ya existen queden
                // clasificadas sin un UPDATE que dependa del orden.
                $table->string('flow_category', 20)->default('legacy');
            }

            if (!Schema::hasColumn(self::TABLA, 'legacy_code')) {
                // A cuál de los cuatro viejos equivale, para los contratos
                // congelados. NO es una clave foránea: es un código estable.
                $table->string('legacy_code', 30)->nullable();
            }
        });
    }

    private function sembrar(): void
    {
        $ahora = now();

        foreach ([['main', self::FLUJO], ['auxiliary', self::AUXILIARES]] as [$categoria, $filas]) {
            foreach ($filas as [$code, $label, $peso, $legacy, $terminal, $estampaResuelto, $estampaCierre]) {
                // Insertar-si-falta y NO upsert: la etiqueta es editable por
                // diseño desde la R1, y un upsert borraría un reetiquetado
                // legítimo en cada despliegue.
                if (DB::table(self::TABLA)->where('code', $code)->exists()) {
                    continue;
                }

                DB::table(self::TABLA)->insert([
                    'code'               => $code,
                    'label'              => $label,
                    'weight'             => $peso,
                    'flow_category'      => $categoria,
                    'legacy_code'        => $legacy,
                    'is_initial'         => false,
                    'is_terminal'        => $terminal,
                    'stamps_resolved_at' => $estampaResuelto,
                    'stamps_closed_at'   => $estampaCierre,
                    'valid_from'         => $ahora,
                    'revision'           => 1,
                    'created_at'         => $ahora,
                    'updated_at'         => $ahora,
                ]);
            }
        }
    }

    private function marcarLegacy(): void
    {
        foreach (self::LEGACY as $code) {
            DB::table(self::TABLA)->where('code', $code)->update([
                'flow_category' => 'legacy',
                // Se equivalen a sí mismos: así ninguna consulta tiene que
                // manejar el caso «no tiene equivalencia».
                'legacy_code'   => $code,
                'updated_at'    => now(),
            ]);
        }
    }

    /**
     * Los tickets NUEVOS nacen en `radicado`; los que ya existen no se tocan.
     *
     * `is_initial` queda en una sola fila, que es lo que el resto del código
     * asume al buscar el estado de apertura.
     */
    private function moverEstadoInicial(): void
    {
        DB::table(self::TABLA)->where('is_initial', true)->update(['is_initial' => false]);
        DB::table(self::TABLA)->where('code', 'radicado')->update([
            'is_initial' => true,
            'updated_at' => now(),
        ]);
    }
};
