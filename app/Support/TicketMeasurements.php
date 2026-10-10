<?php

namespace App\Support;

/**
 * PR F2 · Vocabulario de las pruebas técnicas del ticket (§ 12, § 13 y § 15).
 *
 * POR QUÉ UNA CLASE Y NO UN OCTAVO CATÁLOGO EN BASE DE DATOS
 *
 * Los siete catálogos de la R1 existen porque su ETIQUETA es editable por el
 * operador y su cambio aplica retroactivamente: eso obliga a tenerlos en tablas
 * con versión. Lo de aquí es otra cosa:
 *
 *   · Las FASES y las RAZONES gobiernan una regla de validación —si el ticket
 *     puede cerrarse—. Editarlas en caliente cambiaría el comportamiento del
 *     cierre sin desplegar ni revisar, que es justo lo que no se quiere.
 *   · Las SUGERENCIAS no son valores seleccionables: son un recordatorio de lo
 *     que el documento enumera, y `test_type` sigue siendo texto libre.
 *
 * Es el mismo criterio con el que la matriz de transiciones vive en PHP
 * (`TicketWorkflow`, deuda P-51 aceptada a conciencia).
 */
class TicketMeasurements
{
    /**
     * Fases de la medición. § 12 pide «fase» y § 13 nombra «Inicial» y «Final».
     *
     * `seguimiento` sale del § 8, que en el bloque de Diagnóstico lista «pruebas
     * iniciales, pruebas de seguimiento, causa sospechada…». Las tres están en
     * el documento; no hay una cuarta inventada.
     */
    public const FASE_INICIAL     = 'inicial';
    public const FASE_SEGUIMIENTO = 'seguimiento';
    public const FASE_FINAL       = 'final';

    public const FASES = [self::FASE_INICIAL, self::FASE_SEGUIMIENTO, self::FASE_FINAL];

    /** @return array<string, string> */
    public static function fases(): array
    {
        return [
            self::FASE_INICIAL     => 'Inicial',
            self::FASE_SEGUIMIENTO => 'Seguimiento',
            self::FASE_FINAL       => 'Final',
        ];
    }

    /**
     * Razones por las que NO se pudo tomar la medición final (§ 13).
     *
     * EL DOCUMENTO EXIGE LA LISTA PERO NO LA DA
     *
     * § 13: «Cuando no sea posible obtener la medición final, el usuario deberá
     * seleccionar una razón y escribir la justificación». Pide seleccionar —es
     * decir, lista cerrada— pero en ninguna sección la enumera.
     *
     * Así que se compone con vocabulario que el documento YA usa, en vez de
     * inventar conceptos nuevos. Cada entrada es trazable:
     *
     *   · `cliente_no_permitio`      → A.4 R14 «Cliente no permitió continuar»
     *                                   y A.2 NF «cliente no permitió»
     *   · `no_fue_posible_contactar` → A.4 R15, § 7 (estado auxiliar) y A.2 NF
     *   · `equipo_sin_energia`       → A.1 S09 «Equipo apagado o sin energía»
     *   · `pendiente_tercero`        → § 7 (estado auxiliar) y A.4 R11
     *   · `otro`                     → § 15.8 «"Otro" siempre debe requerir
     *                                   explicación»
     *
     * Queda registrada como decisión **D-16** para que el cliente la confirme o
     * la sustituya. Si la cambia, es un cambio de lista cerrada —no de código
     * inmutable de catálogo— así que no arrastra el coste de la R1.
     *
     * La JUSTIFICACIÓN es obligatoria en los cinco casos, no sólo en `otro`: el
     * § 13 pide razón «y» justificación, con la conjunción, y una razón sin
     * texto no explica por qué no se pudo medir ESTE ticket.
     *
     * @return array<string, string>
     */
    public static function razonesSinPruebaFinal(): array
    {
        return [
            'cliente_no_permitio'      => 'El cliente no permitió continuar',
            'no_fue_posible_contactar' => 'No fue posible contactar al cliente',
            'equipo_sin_energia'       => 'Equipo apagado o sin energía',
            'pendiente_tercero'        => 'Pendiente de un tercero',
            'otro'                     => 'Otro (explicar en la justificación)',
        ];
    }

    /** @return array<int, string> */
    public static function codigosDeRazon(): array
    {
        return array_keys(self::razonesSinPruebaFinal());
    }

    /**
     * Sugerencias de `test_type`, transcritas del § 12.
     *
     * NO SON CÓDIGOS Y NO SON UNA LISTA CERRADA.
     *
     * El § 12 enumera las mediciones en prosa y por tecnología, sin asignarles
     * código —exactamente como el Anexo A.2 hace con las subcausas—. El cliente
     * ya cerró ese criterio el 11/09/2026 en **D-06**: mantenerlas como
     * referencia y no crear códigos individuales. Aquí se aplica igual
     * (decisión **S-4**): `test_type` es texto libre y esto sólo alimenta un
     * `<datalist>` para que el técnico no tenga que escribirlo de memoria.
     *
     * Transcrito literal del documento, sin añadir ni quitar métricas.
     *
     * @return array<string, array<int, string>>
     */
    public static function sugerenciasPorTecnologia(): array
    {
        return [
            'Común a cualquier tecnología' => [
                'Estado del servicio', 'PPPoE', 'RADIUS', 'IP', 'gateway', 'Internet',
                'DNS', 'latencia', 'pérdida', 'velocidad', 'uptime', 'reinicios', 'energía',
            ],
            'Radio' => [
                'Nodo', 'AP', 'VLAN', 'NAS', 'CPE', 'MAC', 'modelo', 'asociación',
                'RSSI', 'SNR', 'CCQ', 'ruido', 'Tx/Rx', 'frecuencia', 'ancho',
                'ethernet', 'PoE', 'transporte',
            ],
            'FTTH' => [
                'OLT', 'tarjeta', 'PON', 'ONU', 'serial', 'VLAN', 'online/offline',
                'LOS', 'RX/TX óptico', 'distancia', 'uptime', 'ethernet', 'PPPoE',
                'última desconexión',
            ],
        ];
    }
}
