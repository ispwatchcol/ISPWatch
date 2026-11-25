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

    /**
     * Desde qué estado terminal se puede REABRIR, y a dónde va el ticket.
     *
     * VA APARTE DE `TRANSICIONES` A PROPÓSITO. Si `cerrado => [reabierto]`
     * estuviera en la matriz general, `PATCH /support/{id}/status` lo aceptaría
     * y cualquiera con `ticket_transition` reabriría un ticket cerrado **sin**
     * `ticket_reopen` y **sin** motivo — que es justo lo que la reapertura
     * existe para impedir. Aquí queda declarado de forma explícita, y lo
     * consulta únicamente el endpoint de reapertura.
     *
     * Los cuatro terminales entran: los dos del flujo nuevo (`cerrado`,
     * `duplicado` — un duplicado que resulta no serlo hay que poder devolverlo)
     * y los dos legacy, para que un ticket anterior al workflow también se
     * pueda reabrir sin tocarlo a mano primero.
     */
    public const REAPERTURA = [
        self::CERRADO   => self::REABIERTO,
        self::DUPLICADO => self::REABIERTO,
        self::CLOSED    => self::REABIERTO,
        self::RESOLVED  => self::REABIERTO,
    ];

    /**
     * Destinos que NO se alcanzan por `PATCH .../status`, porque tienen
     * operación propia con su permiso y sus requisitos.
     */
    public const DESTINOS_CON_ENDPOINT_PROPIO = [
        self::CERRADO   => 'close',
        self::CLOSED    => 'close',
        self::REABIERTO => 'reopen',
    ];

    public static function sePuedeReabrir(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::REAPERTURA);
    }

    /** A qué estado vuelve un ticket al reabrirse, o `null` si no se puede. */
    public static function estadoTrasReabrir(?string $code): ?string
    {
        return self::REAPERTURA[$code] ?? null;
    }

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

    /**
     * Estados por los que el camino AL CIERRE puede pasar, en el recorrido
     * normal de una falla atendida: clasificar, asignar, (visitar), intervenir,
     * restablecer. Quedan fuera a propósito los atajos que la matriz permite
     * pero que dicen otra cosa: «asociado a incidente masivo» y «solución
     * temporal» (exige cierre especial), las pausas, y «en diagnóstico remoto»,
     * que cuenta como RESOLUCIÓN REMOTA en las estadísticas del § 17 —sugerirlo
     * por ser un paso más corto falsearía ese indicador—.
     */
    private const CAMINO_NORMAL = [
        self::EN_CLASIFICACION,
        self::ASIGNADO,
        self::EN_INTERVENCION,
        self::SERVICIO_RESTABLECIDO,
    ];

    /**
     * Pasos que faltan, desde `$desde`, hasta un estado desde el que se puede
     * cerrar. `[]` si ya se puede cerrar desde ahí; `null` si no hay camino
     * (terminal, o estado desconocido).
     *
     * Sirve para que la pantalla diga «para cerrar: En intervención → Servicio
     * restablecido → Cerrar» en vez de esconder el botón sin explicación. Usa
     * la MISMA matriz: cada paso sugerido es una transición válida, y recorrerlo
     * deja un evento por paso en el historial.
     *
     * @return array<int, string>|null
     */
    public static function caminoAlCierre(?string $desde): ?array
    {
        if ($desde === null || self::esTerminal($desde) || !self::conocido($desde)) {
            return null;
        }

        if (in_array($desde, self::PUEDE_CERRAR_DESDE, true)) {
            return [];
        }

        // Búsqueda en anchura: el camino más corto por estados del recorrido normal.
        $cola = [[$desde, []]];
        $visto = [$desde => true];

        while ($cola) {
            [$estado, $camino] = array_shift($cola);

            foreach (self::destinosDesde($estado) as $siguiente) {
                if (isset($visto[$siguiente]) || !in_array($siguiente, self::CAMINO_NORMAL, true)) {
                    continue;
                }

                $nuevo = [...$camino, $siguiente];

                if ($siguiente === self::SERVICIO_RESTABLECIDO) {
                    return $nuevo;
                }

                $visto[$siguiente] = true;
                $cola[] = [$siguiente, $nuevo];
            }
        }

        return null;
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

    // ── Modo del flujo por ISP: formal o simple (bitácora § 133) ─────────
    //
    // TODO LO DE ARRIBA ES EL FLUJO FORMAL, el de la Solicitud Maestra de CNO,
    // y sigue intacto: un ISP en modo formal se comporta exactamente igual que
    // antes de que existiera el modo simple.
    //
    // POR QUÉ EXISTE EL SIMPLE. Esas reglas las pidió un cliente con un proceso
    // formal de mesa de ayuda y se aplicaban a todos. Para un ISP pequeño eran
    // cuatro cambios de estado obligatorios antes de poder cerrar, más causa
    // confirmada, resultado y medición final: los tickets se quedaban abiertos.
    //
    // QUÉ CAMBIA Y QUÉ NO. El simple NO tiene estados propios: usa los mismos
    // códigos del catálogo —el historial, los reportes y la API siguen
    // hablando el mismo idioma— y sólo cambia tres cosas:
    //
    //   1 · la matriz: desde cualquier estado de trabajo se puede ir a «en
    //       proceso» (en_intervencion), a una espera, o cerrar directamente;
    //   2 · los requisitos de cierre: basta con decir qué se hizo (`solution`);
    //       la causa confirmada, el resultado y la medición final se aceptan
    //       pero no se exigen;
    //   3 · las etiquetas que ve el usuario: «Abierto», «En proceso»,
    //       «En espera…», «Resuelto», «Cerrado».
    //
    // Los permisos NO cambian con el modo: cerrar sigue exigiendo `ticket_close`
    // y mover el estado `ticket_transition`. El modo decide qué es posible; el
    // rol, quién puede hacerlo.

    public const MODO_FORMAL = 'formal';
    public const MODO_SIMPLE = 'simple';
    public const MODOS = [self::MODO_FORMAL, self::MODO_SIMPLE];

    /**
     * Modo del ISP. Lo que no se puede leer —tenant inexistente, columna sin
     * migrar, valor raro— es FORMAL: el modo estricto es el seguro, porque
     * equivocarse hacia el simple dejaría cerrar sin los requisitos que un ISP
     * formal exige.
     */
    public static function modoDelTenant(?int $tenantId): string
    {
        if (!$tenantId) {
            return self::MODO_FORMAL;
        }

        try {
            $modo = \Illuminate\Support\Facades\DB::table('tenant')
                ->where('id', $tenantId)
                ->value('ticket_workflow_mode');
        } catch (\Throwable) {
            return self::MODO_FORMAL;
        }

        return $modo === self::MODO_SIMPLE ? self::MODO_SIMPLE : self::MODO_FORMAL;
    }

    public static function esSimple(?string $modo): bool
    {
        return $modo === self::MODO_SIMPLE;
    }

    // ── Grupos de estado ────────────────────────────────────────────────
    //
    // Para la lista (pestañas y contadores) y los colores. Los 22 códigos se
    // reparten en cinco grupos que cualquiera entiende sin conocer el flujo.
    // Es presentación y filtro, NO regla: qué transición cabe lo sigue
    // decidiendo la matriz.

    public const GRUPO_NUEVO       = 'nuevo';
    public const GRUPO_EN_CURSO    = 'en_curso';
    public const GRUPO_EN_ESPERA   = 'en_espera';
    public const GRUPO_PARA_CERRAR = 'para_cerrar';
    public const GRUPO_CERRADO     = 'cerrado';

    public const GRUPOS = [
        self::RADICADO                  => self::GRUPO_NUEVO,
        self::EN_CLASIFICACION          => self::GRUPO_NUEVO,
        self::OPEN                      => self::GRUPO_NUEVO,
        self::REABIERTO                 => self::GRUPO_NUEVO,

        self::EN_DIAGNOSTICO_REMOTO     => self::GRUPO_EN_CURSO,
        self::ASIGNADO                  => self::GRUPO_EN_CURSO,
        self::VISITA_PROGRAMADA         => self::GRUPO_EN_CURSO,
        self::EN_INTERVENCION           => self::GRUPO_EN_CURSO,
        self::IN_PROGRESS               => self::GRUPO_EN_CURSO,
        self::ASOCIADO_INCIDENTE_MASIVO => self::GRUPO_EN_CURSO,

        self::PENDIENTE_CLIENTE         => self::GRUPO_EN_ESPERA,
        self::PENDIENTE_MATERIAL        => self::GRUPO_EN_ESPERA,
        self::PENDIENTE_TERCERO         => self::GRUPO_EN_ESPERA,
        self::PENDIENTE_INFRAESTRUCTURA => self::GRUPO_EN_ESPERA,
        self::NO_FUE_POSIBLE_CONTACTAR  => self::GRUPO_EN_ESPERA,

        self::SERVICIO_RESTABLECIDO     => self::GRUPO_PARA_CERRAR,
        self::EN_OBSERVACION            => self::GRUPO_PARA_CERRAR,
        self::SOLUCION_TEMPORAL         => self::GRUPO_PARA_CERRAR,
        // Legacy: es terminal para el flujo, pero todavía se puede cerrar
        // desde ahí, así que espera a que alguien lo cierre.
        self::RESOLVED                  => self::GRUPO_PARA_CERRAR,

        self::CERRADO                   => self::GRUPO_CERRADO,
        self::CLOSED                    => self::GRUPO_CERRADO,
        self::DUPLICADO                 => self::GRUPO_CERRADO,
    ];

    /** Etiqueta de cada grupo, para pestañas y leyendas. */
    public const ETIQUETAS_DE_GRUPO = [
        self::GRUPO_NUEVO       => 'Nuevo',
        self::GRUPO_EN_CURSO    => 'En curso',
        self::GRUPO_EN_ESPERA   => 'En espera',
        self::GRUPO_PARA_CERRAR => 'Para cerrar',
        self::GRUPO_CERRADO     => 'Cerrado',
    ];

    /** Un código que nadie clasificó cuenta como nuevo: se ve, no se pierde. */
    public static function grupoDe(?string $code): string
    {
        return self::GRUPOS[$code] ?? self::GRUPO_NUEVO;
    }

    /** @return array<int, string> códigos de un grupo */
    public static function codigosDelGrupo(string $grupo): array
    {
        return array_keys(array_filter(self::GRUPOS, fn (string $g) => $g === $grupo));
    }

    // ── Modo simple: etiquetas ───────────────────────────────────────────

    /**
     * Cómo se llama cada estado en el modo simple. Varios códigos comparten
     * nombre a propósito: para quien atiende, «asignado» y «en intervención»
     * son lo mismo — alguien lo está trabajando.
     */
    public const ETIQUETAS_SIMPLES = [
        self::RADICADO                  => 'Abierto',
        self::EN_CLASIFICACION          => 'Abierto',
        self::OPEN                      => 'Abierto',
        self::REABIERTO                 => 'Reabierto',

        self::EN_DIAGNOSTICO_REMOTO     => 'En proceso',
        self::ASIGNADO                  => 'En proceso',
        self::VISITA_PROGRAMADA         => 'En proceso',
        self::EN_INTERVENCION           => 'En proceso',
        self::IN_PROGRESS               => 'En proceso',
        self::ASOCIADO_INCIDENTE_MASIVO => 'En proceso · falla masiva',

        self::PENDIENTE_CLIENTE         => 'En espera del cliente',
        self::PENDIENTE_MATERIAL        => 'En espera de material',
        self::PENDIENTE_TERCERO         => 'En espera de un tercero',
        self::PENDIENTE_INFRAESTRUCTURA => 'En espera de infraestructura',
        self::NO_FUE_POSIBLE_CONTACTAR  => 'Sin contacto con el cliente',

        self::SERVICIO_RESTABLECIDO     => 'Resuelto',
        self::EN_OBSERVACION            => 'Resuelto',
        self::SOLUCION_TEMPORAL         => 'Resuelto (temporal)',
        self::RESOLVED                  => 'Resuelto',

        self::CERRADO                   => 'Cerrado',
        self::CLOSED                    => 'Cerrado',
        self::DUPLICADO                 => 'Duplicado',
    ];

    public static function etiquetaSimple(?string $code): ?string
    {
        return $code === null ? null : (self::ETIQUETAS_SIMPLES[$code] ?? null);
    }

    // ── Modo simple: matriz ──────────────────────────────────────────────

    /** Las esperas que ofrece el simple: las mismas pausas del formal. */
    private const ESPERAS_SIMPLES = self::PAUSAS;

    /**
     * Destinos del modo simple. Desde cualquier estado de trabajo: ponerlo
     * «en proceso», ponerlo en espera, marcarlo duplicado o cerrarlo. Cerrar
     * va aparte (endpoint propio, como en el formal) y no figura aquí.
     *
     * @return array<int, string>
     */
    private static function destinosSimples(?string $desde): array
    {
        if ($desde === null || self::esTerminal($desde) || !self::conocido($desde)) {
            return [];
        }

        $destinos = [self::EN_INTERVENCION, ...self::ESPERAS_SIMPLES, self::DUPLICADO];

        // Nunca el estado en el que ya está: moverlo a sí mismo es un 422.
        return array_values(array_filter(
            $destinos,
            fn (string $d) => $d !== $desde,
        ));
    }

    /**
     * Destinos válidos según el modo. El formal es la matriz de siempre.
     *
     * @return array<int, string>
     */
    public static function destinosSegunModo(?string $desde, ?string $modo): array
    {
        return self::esSimple($modo) ? self::destinosSimples($desde) : self::destinosDesde($desde);
    }

    public static function permiteSegunModo(?string $desde, ?string $hacia, ?string $modo): bool
    {
        return $hacia !== null && in_array($hacia, self::destinosSegunModo($desde, $modo), true);
    }

    /** ¿Se puede cerrar desde aquí? En el simple, desde cualquier estado vivo. */
    public static function puedeCerrarDesde(?string $code, ?string $modo): bool
    {
        if (self::esSimple($modo)) {
            // `resolved` es terminal para el flujo (legacy) pero se cierra.
            if ($code === self::RESOLVED) {
                return true;
            }

            return $code !== null && self::conocido($code) && !self::esTerminal($code);
        }

        return in_array($code, self::PUEDE_CERRAR_DESDE, true);
    }

    /** En el simple se propone desde donde se podría cerrar. */
    public static function puedeProponerDesde(?string $code, ?string $modo): bool
    {
        if (self::esSimple($modo)) {
            return self::puedeCerrarDesde($code, $modo);
        }

        return in_array($code, self::PUEDE_PROPONER_DESDE, true);
    }

    /**
     * Pasos que faltan para poder cerrar. En el simple no falta ninguno: se
     * cierra desde donde esté.
     *
     * @return array<int, string>|null
     */
    public static function caminoAlCierreSegunModo(?string $desde, ?string $modo): ?array
    {
        if (self::esSimple($modo)) {
            return self::puedeCerrarDesde($desde, $modo) ? [] : null;
        }

        return self::caminoAlCierre($desde);
    }

    /**
     * Requisitos de cierre según el modo. El simple sólo pide lo que se hizo:
     * sin eso el expediente no dice nada, y es lo único que cualquier ISP
     * necesita saber de un ticket cerrado.
     *
     * @return array<string, string>
     */
    public static function requisitosDeCierre(?string $modo): array
    {
        return self::esSimple($modo)
            ? ['solution' => 'Qué se hizo (acción o solución)']
            : self::REQUISITOS_DE_CIERRE;
    }

    /** @return array<string, string> */
    public static function requisitosDePropuesta(?string $modo): array
    {
        return self::esSimple($modo)
            ? ['solution' => 'Qué se hizo (acción o solución)']
            : self::REQUISITOS_DE_PROPUESTA;
    }

    /** La regla 5 del § 15 (medición final o justificación) es del formal. */
    public static function exigePruebaFinal(?string $modo): bool
    {
        return !self::esSimple($modo);
    }

    /** La regla 9 (solución temporal exige autorización) también es del formal. */
    public static function solucionTemporalExigeAutorizacion(?string $modo): bool
    {
        return !self::esSimple($modo);
    }

    /**
     * El paso que la pantalla debe ofrecer como PRINCIPAL desde aquí, o null
     * si lo principal es cerrar (o no hay nada que hacer).
     *
     *   · formal: el siguiente estado del camino al cierre;
     *   · simple: «en proceso» mientras nadie lo trabaja; después, cerrar.
     */
    public static function siguientePaso(?string $desde, ?string $modo): ?string
    {
        if ($desde === null || self::esTerminal($desde)) {
            return null;
        }

        if (self::esSimple($modo)) {
            $grupo = self::grupoDe($desde);

            return in_array($grupo, [self::GRUPO_NUEVO, self::GRUPO_EN_ESPERA], true)
                && $desde !== self::EN_INTERVENCION
                ? self::EN_INTERVENCION
                : null;
        }

        $camino = self::caminoAlCierre($desde);

        return $camino[0] ?? null;
    }

    /**
     * Estado en el que queda un ticket que nace con técnico asignado, en el
     * flujo formal. Asignar ES clasificar y asignar; hacer que alguien pulse
     * dos botones más para decir lo que ya se sabe era la mitad de la queja.
     * En el simple no cambia nada: sigue «Abierto» hasta que alguien lo trabaje.
     *
     * @return array<int, string> pasos desde el estado inicial, en orden
     */
    public static function pasosAlAsignar(?string $desde, ?string $modo): array
    {
        if (self::esSimple($modo)) {
            return [];
        }

        return match ($desde) {
            self::RADICADO         => [self::EN_CLASIFICACION, self::ASIGNADO],
            self::EN_CLASIFICACION => [self::ASIGNADO],
            self::OPEN             => [self::ASIGNADO],
            self::REABIERTO        => [self::ASIGNADO],
            default                => [],
        };
    }
}
