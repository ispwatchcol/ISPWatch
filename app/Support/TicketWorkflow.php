<?php

namespace App\Support;

/**
 * Qué puede pasarle a un ticket y desde dónde.
 *
 * DE DÓNDE SALE ESTO
 *
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`:
 *
 *   · § 7  · Ciclo de vida requerido — los nueve estados del flujo y los nueve
 *           auxiliares. El diagrama marca «(cuando aplique)» en VISITA
 *           PROGRAMADA y «(opcional)» en EN OBSERVACIÓN: por eso las dos se
 *           pueden saltar.
 *   · § 15 · Reglas obligatorias de cierre — los diez puntos.
 *   · § 18 · Roles, permisos y auditoría — quién propone y quién cierra.
 *
 * POR QUÉ UNA CLASE Y NO UNA TABLA
 *
 * Los catálogos de la R1 viven en base de datos porque son VOCABULARIO: el ISP
 * puede reetiquetarlos y —en tres de ellos— añadir los suyos. Una transición no
 * es vocabulario: es una regla de negocio, y el requerimiento la trata como tal
 * («cada estado y transición tendrá código técnico estable, versión y semántica
 * documentada»). Además nadie puede administrar hoy una tabla de transiciones:
 * no hay pantalla, y **D-13** —quién administra los catálogos— sigue delegada
 * sin resolver. Una tabla que nadie puede editar es una tabla peor que una
 * constante, porque aparenta ser configurable.
 *
 * Se expone por API (`GET /support/{id}/transitions`) para que la interfaz
 * pinte lo que el servidor permite, en vez de mantener su propia copia.
 *
 * LO QUE ESTA CLASE NO DECIDE
 *
 * Los PERMISOS. La matriz dice si un movimiento es posible; el controlador dice
 * si quien lo pide puede hacerlo. Son dos preguntas distintas y mezclarlas
 * llevaría a que ampliar un rol obligara a tocar el flujo.
 */
class TicketWorkflow
{
    // ── Los nueve del flujo (§ 7) ────────────────────────────────────────

    public const RADICADO              = 'radicado';
    public const EN_CLASIFICACION      = 'en_clasificacion';
    public const EN_DIAGNOSTICO_REMOTO = 'en_diagnostico_remoto';
    public const ASIGNADO              = 'asignado';
    public const VISITA_PROGRAMADA     = 'visita_programada';
    public const EN_INTERVENCION       = 'en_intervencion';
    public const SERVICIO_RESTABLECIDO = 'servicio_restablecido';
    public const EN_OBSERVACION        = 'en_observacion';
    public const CERRADO               = 'cerrado';

    // ── Los nueve auxiliares (§ 7) ───────────────────────────────────────

    public const PENDIENTE_CLIENTE         = 'pendiente_cliente';
    public const PENDIENTE_MATERIAL        = 'pendiente_material';
    public const PENDIENTE_TERCERO         = 'pendiente_tercero';
    public const PENDIENTE_INFRAESTRUCTURA = 'pendiente_infraestructura';
    public const ASOCIADO_INCIDENTE_MASIVO = 'asociado_incidente_masivo';
    public const DUPLICADO                 = 'duplicado';
    public const NO_FUE_POSIBLE_CONTACTAR  = 'no_fue_posible_contactar';
    public const SOLUCION_TEMPORAL         = 'solucion_temporal';
    public const REABIERTO                 = 'reabierto';

    // ── Los cuatro de antes de la Solicitud ──────────────────────────────

    public const OPEN        = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED    = 'resolved';
    public const CLOSED      = 'closed';

    /**
     * Auxiliares que PAUSAN el trabajo sin terminarlo. Se puede entrar en
     * cualquiera de ellos desde cualquier estado operativo, porque la espera
     * puede aparecer en cualquier momento: el cliente no contesta durante el
     * diagnóstico, el material falta al llegar a la visita.
     */
    private const PAUSAS = [
        self::PENDIENTE_CLIENTE,
        self::PENDIENTE_MATERIAL,
        self::PENDIENTE_TERCERO,
        self::PENDIENTE_INFRAESTRUCTURA,
        self::NO_FUE_POSIBLE_CONTACTAR,
    ];

    /**
     * Estados a los que se puede volver cuando la espera termina.
     *
     * No se vuelve a `radicado` ni a `en_clasificacion`: el ticket ya pasó por
     * ahí y retroceder a la recepción borraría el sentido del recorrido.
     */
    private const REANUDABLES = [
        self::EN_DIAGNOSTICO_REMOTO,
        self::ASIGNADO,
        self::VISITA_PROGRAMADA,
        self::EN_INTERVENCION,
    ];

    /**
     * La matriz. Estado origen => estados a los que puede ir.
     *
     * Las vueltas atrás están permitidas dentro del tramo operativo y son
     * deliberadas: un técnico que llega a la visita y descubre que la falla era
     * remota tiene que poder devolver el ticket a diagnóstico. Lo que no se
     * permite nunca es salir de un estado terminal — de ahí sólo se sale
     * reabriendo, que es una operación con su permiso y su motivo.
     */
    private const TRANSICIONES = [
        self::RADICADO => [
            self::EN_CLASIFICACION,
            self::DUPLICADO,
            self::ASOCIADO_INCIDENTE_MASIVO,
            ...self::PAUSAS,
        ],

        self::EN_CLASIFICACION => [
            self::EN_DIAGNOSTICO_REMOTO,
            self::ASIGNADO,
            self::DUPLICADO,
            self::ASOCIADO_INCIDENTE_MASIVO,
            ...self::PAUSAS,
        ],

        self::EN_DIAGNOSTICO_REMOTO => [
            self::ASIGNADO,
            self::VISITA_PROGRAMADA,
            // El diagnóstico remoto puede resolver la falla sin visita: es el
            // indicador de «resolución remota» que pide el § 17.
            self::SERVICIO_RESTABLECIDO,
            self::EN_CLASIFICACION,
            self::DUPLICADO,
            self::ASOCIADO_INCIDENTE_MASIVO,
            ...self::PAUSAS,
        ],

        self::ASIGNADO => [
            // «VISITA PROGRAMADA (cuando aplique)»: se puede saltar.
            self::VISITA_PROGRAMADA,
            self::EN_INTERVENCION,
            self::EN_DIAGNOSTICO_REMOTO,
            self::ASOCIADO_INCIDENTE_MASIVO,
            ...self::PAUSAS,
        ],

        self::VISITA_PROGRAMADA => [
            self::EN_INTERVENCION,
            self::ASIGNADO,
            self::EN_DIAGNOSTICO_REMOTO,
            ...self::PAUSAS,
        ],

        self::EN_INTERVENCION => [
            self::SERVICIO_RESTABLECIDO,
            self::SOLUCION_TEMPORAL,
            self::VISITA_PROGRAMADA,
            self::EN_DIAGNOSTICO_REMOTO,
            ...self::PAUSAS,
        ],

        self::SERVICIO_RESTABLECIDO => [
            // «EN OBSERVACIÓN (opcional)»: se puede saltar directo al cierre.
            self::EN_OBSERVACION,
            self::CERRADO,
            // La falla reaparece antes de cerrar: se vuelve a intervenir sin
            // necesidad de reabrir, porque el ticket nunca llegó a cerrarse.
            self::EN_INTERVENCION,
        ],

        self::EN_OBSERVACION => [
            self::CERRADO,
            self::EN_INTERVENCION,
        ],

        self::SOLUCION_TEMPORAL => [
            self::EN_INTERVENCION,
            self::SERVICIO_RESTABLECIDO,
            // Cerrar con solución temporal es posible, pero el § 15.9 exige
            // «seguimiento o autorización»: el controlador lo trata como
            // requisito faltante y obliga a pasar por el cierre excepcional.
            self::CERRADO,
            ...self::PAUSAS,
        ],

        // Las pausas devuelven al trabajo.
        self::PENDIENTE_CLIENTE         => [...self::REANUDABLES, self::NO_FUE_POSIBLE_CONTACTAR, self::DUPLICADO],
        self::PENDIENTE_MATERIAL        => [...self::REANUDABLES],
        self::PENDIENTE_TERCERO         => [...self::REANUDABLES],
        self::PENDIENTE_INFRAESTRUCTURA => [...self::REANUDABLES, self::ASOCIADO_INCIDENTE_MASIVO],
        self::NO_FUE_POSIBLE_CONTACTAR  => [...self::REANUDABLES, self::PENDIENTE_CLIENTE, self::DUPLICADO],

        // Ticket colgado de un incidente masivo: se resuelve cuando se resuelve
        // el padre, o vuelve al flujo si resulta no ser el mismo problema.
        self::ASOCIADO_INCIDENTE_MASIVO => [
            self::SERVICIO_RESTABLECIDO,
            self::EN_INTERVENCION,
            self::EN_DIAGNOSTICO_REMOTO,
            self::CERRADO,
        ],

        // Reabierto: vuelve al tramo de trabajo.
        self::REABIERTO => [...self::REANUDABLES, ...self::PAUSAS, self::SERVICIO_RESTABLECIDO],

        // Terminales. De aquí sólo se sale reabriendo.
        self::CERRADO   => [],
        self::DUPLICADO => [],

        // ── Los cuatro viejos ────────────────────────────────────────────
        //
        // Existen para que los tickets que ya estaban abiertos cuando esto se
        // desplegó puedan entrar al flujo nuevo sin que nadie los toque a mano.
        // No se puede VOLVER a ellos: son una puerta de entrada, no un destino.

        self::OPEN => [
            self::EN_CLASIFICACION,
            self::EN_DIAGNOSTICO_REMOTO,
            self::ASIGNADO,
            self::DUPLICADO,
            ...self::PAUSAS,
        ],

        self::IN_PROGRESS => [
            self::EN_DIAGNOSTICO_REMOTO,
            self::ASIGNADO,
            self::VISITA_PROGRAMADA,
            self::EN_INTERVENCION,
            self::SERVICIO_RESTABLECIDO,
            self::SOLUCION_TEMPORAL,
            self::DUPLICADO,
            ...self::PAUSAS,
        ],

        self::RESOLVED => [
            self::EN_OBSERVACION,
            self::EN_INTERVENCION,
            self::CERRADO,
        ],

        self::CLOSED => [],
    ];

    /** Estados desde los que se puede CERRAR. Derivado de la matriz. */
    public const PUEDE_CERRAR_DESDE = [
        self::SERVICIO_RESTABLECIDO,
        self::EN_OBSERVACION,
        self::SOLUCION_TEMPORAL,
        self::ASOCIADO_INCIDENTE_MASIVO,
        self::RESOLVED,
    ];

    /**
     * Estados desde los que se puede PROPONER el cierre.
     *
     * El § 18 da la propuesta al Técnico de campo, después de «pruebas
     * finales». Proponer cerrar un ticket cuyo servicio todavía no volvió no
     * significa nada, así que la propuesta exige que ya esté restablecido —o
     * con solución temporal, que es un restablecimiento a medias y por eso
     * necesita que alguien lo mire.
     */
    public const PUEDE_PROPONER_DESDE = [
        self::SERVICIO_RESTABLECIDO,
        self::SOLUCION_TEMPORAL,
        self::EN_OBSERVACION,
        self::RESOLVED,
    ];

    /**
     * Dónde queda un ticket cuando se propone cerrarlo.
     *
     * `EN OBSERVACIÓN` es el estado que el documento coloca inmediatamente
     * antes de CERRADO. Usarlo como «propuesto, esperando al supervisor» es una
     * interpretación nuestra —el documento no nombra un estado de propuesta— y
     * queda registrada como supuesto **S-5** del diseño.
     */
    public const ESTADO_TRAS_PROPUESTA = self::EN_OBSERVACION;

    /** Dónde queda un ticket al reabrirse. § 7 lo lista como auxiliar. */
    public const ESTADO_TRAS_REAPERTURA = self::REABIERTO;

    /** Estados terminales: un ticket ahí no se sigue trabajando. */
    public const TERMINALES = [self::CERRADO, self::DUPLICADO, self::CLOSED, self::RESOLVED];

    /**
     * Estados en los que hay TRABAJO VIVO, para el archivado del PR C.
     *
     * Antes era la pareja `open`/`in_progress` escrita a mano. Ahora se deduce:
     * es todo lo que no es terminal. Así, añadir un estado al flujo no deja un
     * hueco por el que se pueda archivar trabajo en curso sin las barreras.
     *
     * @return array<int, string>
     */
    public static function estadosActivos(): array
    {
        $todos = array_keys(self::TRANSICIONES);

        return array_values(array_diff($todos, self::TERMINALES));
    }

    /** ¿Existe este código en la matriz? */
    public static function conocido(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::TRANSICIONES);
    }

    /**
     * Destinos válidos desde `$desde`.
     *
     * Un estado desconocido —una fila de catálogo añadida a mano después—
     * devuelve lista vacía en vez de reventar: preferimos un ticket que no se
     * puede mover a una pantalla que no carga.
     *
     * @return array<int, string>
     */
    public static function destinosDesde(?string $desde): array
    {
        return self::TRANSICIONES[$desde] ?? [];
    }

    public static function permite(?string $desde, ?string $hacia): bool
    {
        return $hacia !== null && in_array($hacia, self::destinosDesde($desde), true);
    }

    public static function esTerminal(?string $code): bool
    {
        return in_array($code, self::TERMINALES, true);
    }

    // ── Reglas de cierre (§ 15) ──────────────────────────────────────────

    /**
     * Campo del diagnóstico => qué regla del § 15 lo exige.
     *
     * Son las TRES que el modelo de datos actual puede comprobar. Las otras
     * siete del documento no tienen dónde apoyarse todavía:
     *
     *   4 · «fecha y hora de restablecimiento cuando existió indisponibilidad»
     *       — se cumple por construcción: el estado `servicio_restablecido`
     *       estampa `resolved_at` desde el catálogo, no hace falta comprobarlo.
     *   5 · «prueba final o justificación de por qué no fue posible»
     *   6 · «infraestructura afectada o clasificación red interna / no aplica»
     *   7 · «validación del cliente separada de la restauración técnica»
     *   8 · «"Otro" siempre debe requerir explicación»
     *   9 · «solución temporal, pendiente de tercero y no resuelto deben
     *       generar seguimiento o autorización» — parcial: la solución temporal
     *       obliga a pasar por el cierre excepcional, que ES la autorización.
     *
     * Las 5, 6, 7 y 8 exigen campos de captura que el ticket todavía no tiene
     * (pruebas finales, infraestructura afectada con valor «no aplica»,
     * validación del cliente). Están anotadas en el seguimiento y son la razón
     * por la que **F1-10 no queda cumplido**, sino parcial.
     */
    public const REQUISITOS_DE_CIERRE = [
        'confirmed_cause' => 'Causa confirmada (regla 1 del § 15)',
        'solution'        => 'Acción o solución registrada (regla 2)',
        'result'          => 'Resultado técnico (regla 3)',
    ];

    /** Lo que hace falta para PROPONER: el técnico de campo no confirma causa. */
    public const REQUISITOS_DE_PROPUESTA = [
        'solution' => 'Acción o solución registrada (regla 2 del § 15)',
        'result'   => 'Resultado técnico (regla 3)',
    ];
}
