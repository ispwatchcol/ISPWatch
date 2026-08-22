<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PR funcional #1 — vocabulario de diagnóstico del ticket (Anexo A del cliente).
 *
 * FUENTE ÚNICA
 * docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx, Anexo A:
 *   A.1 Catálogo de síntomas           S01–S16   (16 códigos literales)
 *   A.2 Familias de causa raíz         RF…NF     (7 familias con código literal)
 *   A.3 Catálogo de acciones           AC01–AC20 (20 códigos literales)
 *   A.4 Resultados de cierre           R01–R15   (15 códigos literales)
 *
 * Los códigos y etiquetas se transcriben TAL CUAL del documento. No se traducen,
 * no se abrevian y no se reordenan: son el contrato con el integrador.
 *
 * LO QUE NO SE SIEMBRA, Y POR QUÉ
 *
 * Las SUBCAUSAS. El Anexo A las enumera en prosa dentro de la columna «Subcausas
 * de referencia» («Señal baja; interferencia; saturación; …») y **no les asigna
 * código**. Inventarlos aquí sería fabricar contrato: los códigos son inmutables
 * una vez sembrados, así que un `RF01` improvisado quedaría para siempre o habría
 * que retirarlo dejando basura en el histórico.
 *
 * Se siembran por tanto sólo las 7 familias, que sí tienen código oficial, y el
 * texto de las subcausas viaja en `description` como REFERENCIA legible, nunca
 * como valor seleccionable. Cuando el cliente asigne códigos, cada subcausa
 * entrará como fila propia con `group_code` apuntando a su familia — la columna
 * ya existe y está libre justamente para eso.
 *
 * MAPEO DE NOMBRE A REVISAR
 * El cliente llama «Acción» (A.3) a lo que este esquema guarda en
 * `ticket_solution` / `support_ticket.solution_id`. El CÓDIGO es el mismo
 * (AC01…AC20) y es lo que consume el integrador, pero el nombre de la tabla no
 * coincide con el del requerimiento. Queda anotado como decisión pendiente en
 * docs/cliente/CNO/SEGUIMIENTO_MODULO_TICKETS.md; renombrarla tocaría el esquema
 * de la R1 y no corresponde a este PR.
 *
 * POR QUÉ MIGRACIÓN Y NO SEEDER
 * `migrate:both` aplica migraciones en `ispwatch_dev` y en `public`, pero NUNCA
 * siembra `public`; y el despliegue de App Platform ejecuta `migrate --force`
 * dentro del `run_command`, sin seeders. Un catálogo que dependiera del seeder
 * quedaría vacío en producción. Ya ocurrió con `cut_type` (ver 2026_07_31_000001).
 *
 * POR QUÉ «INSERTAR LO QUE FALTA» Y NO UPSERT
 * Un upsert sobrescribiría `label` y `description` en cada despliegue. Por diseño
 * de la R1 la etiqueta es EDITABLE y su cambio aplica retroactivamente: pisarla
 * borraría un reetiquetado legítimo del operador. Insertar sólo lo ausente es
 * idempotente igual y no destruye nada.
 */
return new class extends Migration
{
    /** A.1 · Síntomas. Código => etiqueta, transcritos del Anexo A. */
    private const SINTOMAS = [
        'S01' => 'Sin Internet',
        'S02' => 'Servicio intermitente',
        'S03' => 'Velocidad baja',
        'S04' => 'Latencia alta',
        'S05' => 'Pérdida de paquetes',
        'S06' => 'Cobertura Wi-Fi deficiente',
        'S07' => 'Wi-Fi conectado, pero sin Internet',
        'S08' => 'No autentica o no obtiene conexión',
        'S09' => 'Equipo apagado o sin energía',
        'S10' => 'Equipo reiniciándose',
        'S11' => 'Daño físico visible',
        'S12' => 'Señal RF deficiente',
        'S13' => 'Alarma o pérdida de señal óptica',
        'S14' => 'Página o aplicación específica',
        'S15' => 'IP pública o servicio especial',
        'S16' => 'Otro síntoma técnico; explicación obligatoria',
    ];

    /**
     * A.2 · Familias de causa raíz. Código => [categoría, subcausas de referencia].
     *
     * La segunda posición es TEXTO DE REFERENCIA para quien diligencia, no una
     * lista de valores seleccionables. Ver la nota de cabecera.
     */
    private const FAMILIAS_DE_CAUSA = [
        'RF' => ['Radiofrecuencia', 'Señal baja; interferencia; saturación; desalineación; hardware; cable/PoE; canal/configuración; capacidad.'],
        'FO' => ['Fibra óptica', 'Corte/drop; conector/empalme; potencia; ONU; splitter/ODN; PON/OLT; patchcord; configuración/VLAN.'],
        'CL' => ['Cliente / instalación interna', 'Energía; router; Wi-Fi; cableado; equipo; reset; inestabilidad eléctrica; saturación local.'],
        'AA' => ['Autenticación y direccionamiento', 'PPPoE; RADIUS/NAS; sesión bloqueada; IP/pool; suspensión; perfil o plan.'],
        'RE' => ['Red ISP', 'VLAN/bridge; routing; NAT/firewall/PBR; router de nodo; transporte; DNS/DHCP; congestión; core/cabecera.'],
        'EX' => ['Externo', 'Proveedor upstream; energía comercial; clima; terceros; vandalismo/hurto.'],
        'NF' => ['Sin falla confirmada', 'No encontrada; no reproducible; dentro de parámetros; cliente no permitió; no contacto.'],
    ];

    /** A.3 · Acciones. Se guardan en `ticket_solution`; ver la nota de cabecera. */
    private const ACCIONES = [
        'AC01' => 'Validación remota',
        'AC02' => 'Reinicio o reconexión',
        'AC03' => 'Reconfiguración',
        'AC04' => 'Corrección PPPoE o RADIUS',
        'AC05' => 'Cambio de cable o conector',
        'AC06' => 'Cambio de PoE o fuente',
        'AC07' => 'Cambio de CPE',
        'AC08' => 'Cambio de router',
        'AC09' => 'Cambio de ONU',
        'AC10' => 'Realineación',
        'AC11' => 'Cambio de frecuencia o canal',
        'AC12' => 'Migración de AP o sector',
        'AC13' => 'Reparación o empalme de fibra',
        'AC14' => 'Cambio de puerto PON',
        'AC15' => 'Ajuste VLAN, routing, NAT o firewall',
        'AC16' => 'Corrección de estado comercial',
        'AC17' => 'Escalamiento a proveedor',
        'AC18' => 'Orientación al usuario',
        'AC19' => 'Monitoreo sin intervención',
        'AC20' => 'Otra acción',
    ];

    /** A.4 · Resultados de cierre. */
    private const RESULTADOS = [
        'R01' => 'Solucionado remotamente',
        'R02' => 'Solucionado en primera visita',
        'R03' => 'Solucionado después de varias intervenciones',
        'R04' => 'Restablecido por incidente masivo',
        'R05' => 'Restablecido por recuperación del proveedor',
        'R06' => 'Problema localizado en red interna del cliente',
        'R07' => 'Sin falla técnica confirmada',
        'R08' => 'Falla no reproducible',
        'R09' => 'Solución temporal aplicada',
        'R10' => 'Requiere intervención adicional de infraestructura',
        'R11' => 'Pendiente de tercero',
        'R12' => 'No resuelto',
        'R13' => 'Duplicado y vinculado',
        'R14' => 'Cliente no permitió continuar',
        'R15' => 'No fue posible contactar',
    ];

    public function up(): void
    {
        // `ticket_symptom`, `ticket_cause` y `ticket_solution` son extensibles por
        // tenant: `tenant_id = NULL` marca la fila de PLATAFORMA, visible para
        // todos los ISP y con código estable en toda la instalación. Las filas
        // propias de un tenant conviven aparte y esta siembra no las toca.
        //
        // `ticket_result` es global estricto y ni siquiera tiene `tenant_id`.
        $this->sembrar('ticket_symptom', self::SINTOMAS, conTenant: true);
        $this->sembrar('ticket_solution', self::ACCIONES, conTenant: true);
        $this->sembrar('ticket_result', self::RESULTADOS, conTenant: false);
        $this->sembrarFamiliasDeCausa();

        // La versión sube para que un integrador que cachee el catálogo detecte
        // el cambio sin volver a descargarlo entero.
        foreach (['symptom', 'cause', 'solution', 'result'] as $catalogo) {
            DB::table('ticket_catalog_version')
                ->where('catalog', $catalogo)
                ->update(['version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    /**
     * Inserta sólo los códigos ausentes del ámbito de PLATAFORMA.
     *
     * `weight` se deriva de la posición en el Anexo A (10, 20, 30…) para que el
     * desplegable conserve el orden del documento y quede hueco entre valores
     * por si el cliente intercala uno más adelante.
     *
     * `category_id` de los síntomas se deja en NULL a propósito: el Anexo A no
     * relaciona los síntomas con las categorías de ISPWatch, y hacerlo aquí
     * embebería una decisión que además depende de D-02 (separación
     * soporte/facturación), todavía sin resolver con el cliente.
     */
    private function sembrar(string $tabla, array $filas, bool $conTenant): void
    {
        $consulta = DB::table($tabla);

        if ($conTenant) {
            $consulta->whereNull('tenant_id');
        }

        $existentes = $consulta->pluck('code')->all();
        $peso = 0;

        foreach ($filas as $code => $label) {
            $peso += 10;

            if (in_array($code, $existentes, true)) {
                continue;
            }

            DB::table($tabla)->insert(($conTenant ? ['tenant_id' => null] : []) + [
                'code'       => $code,
                'label'      => $label,
                'weight'     => $peso,
                'valid_from' => now(),
                'revision'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Las familias llevan además el texto de subcausas en `description`. */
    private function sembrarFamiliasDeCausa(): void
    {
        $existentes = DB::table('ticket_cause')->whereNull('tenant_id')->pluck('code')->all();
        $peso = 0;

        foreach (self::FAMILIAS_DE_CAUSA as $code => [$label, $subcausas]) {
            $peso += 10;

            if (in_array($code, $existentes, true)) {
                continue;
            }

            DB::table('ticket_cause')->insert([
                'tenant_id'   => null,
                'code'        => $code,
                'label'       => $label,
                'description' => $subcausas,
                // NULL porque estas filas SON el nivel superior. Cuando el
                // cliente asigne códigos a las subcausas, cada una entrará con
                // `group_code` apuntando a su familia.
                'group_code'  => null,
                'weight'      => $peso,
                'valid_from'  => now(),
                'revision'    => 1,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /**
     * No se borra nada.
     *
     * Los códigos de catálogo son inmutables y `support_ticket` los referencia
     * con `ON DELETE RESTRICT`: borrarlos dejaría tickets sin poder decir cuál
     * fue su síntoma o su causa. Retirar un valor del catálogo se hace poniéndole
     * `valid_until`, nunca con un DELETE. Revertir esta migración sólo devuelve
     * la versión del catálogo.
     */
    public function down(): void
    {
        foreach (['symptom', 'cause', 'solution', 'result'] as $catalogo) {
            DB::table('ticket_catalog_version')
                ->where('catalog', $catalogo)
                ->where('version', '>', 1)
                ->update(['version' => DB::raw('version - 1'), 'updated_at' => now()]);
        }
    }
};
