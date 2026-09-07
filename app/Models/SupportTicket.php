<?php

namespace App\Models;

use App\Support\TicketCatalogs;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;

class SupportTicket extends Model
{
    use BelongsToTenant;

    protected $table = 'support_ticket';

    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    const CATEGORY_TECHNICAL = 'technical';
    const CATEGORY_BILLING = 'billing';
    const CATEGORY_SERVICES = 'services';
    const CATEGORY_GENERAL = 'general';

    protected $fillable = [
        'user_id',
        'staff_id',
        'sectorial_id',
        'tenant_id',
        'subject',
        'description',
        // R3: estas tres ya NO son columnas. Siguen aquí porque son el nombre
        // con el que entran los datos —`create(['status' => 'open'])` es lo que
        // escriben el controlador y los tests—; el mutator las traduce a
        // `status_id`. Quitarlas de `$fillable` haría que la asignación masiva
        // las descartara en silencio y el ticket naciera sin estado.
        'status',
        'priority',
        'category',
        'status_id',
        'priority_id',
        'category_id',
        'symptom_id',
        'suspected_cause_id',
        'confirmed_cause_id',
        'solution_id',
        'result_id',
        // PR #2: los nombres públicos del diagnóstico, que entran como CÓDIGO y
        // el mutator traduce a la clave foránea de arriba. Van aquí por lo mismo
        // que `status`/`priority`/`category`: sin ellos la asignación masiva los
        // descartaría en silencio y el diagnóstico no se guardaría.
        'symptom',
        'suspected_cause',
        'confirmed_cause',
        'solution',
        'result',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    /**
     * FASE 1 · R3 — `status`, `priority` y `category` YA NO SON COLUMNAS.
     *
     * Son atributos calculados a partir de `status_id`, `priority_id` y
     * `category_id`. Sin declararlos aquí **desaparecerían del JSON sin dar
     * ningún error**: Eloquent sólo serializa columnas reales más `$appends`, y
     * al dropear las columnas dejaron de estar en `$attributes`. Comprobado
     * ejecutando contra una base ya migrada, no por inspección.
     *
     * Es decir: esta línea es lo único que mantiene vivas las tres claves en las
     * respuestas del panel. Quitarla rompe el frontend en silencio.
     */
    protected $appends = [
        'status', 'priority', 'category',
        'status_label', 'priority_label', 'category_label',
        // PR #2 — el diagnóstico viaja agrupado, no como diez claves sueltas.
        'diagnosis',
    ];

    /**
     * FASE 1 · R3 — la clave foránea es la única representación que existe.
     *
     * Nombre público del atributo => [columna real, tabla de catálogo].
     *
     * Cómo se llegó hasta aquí, porque el sentido de la flecha cambió dos veces
     * y el historial explica por qué el código tiene la forma que tiene:
     *
     *   R1    se escribía el enum y la clave foránea se rellenaba a partir de él.
     *   R2    se invirtió: la clave foránea pasó a mandar, el enum quedó de copia.
     *   R2.5  se dejó de escribir la copia; las columnas quedaron congeladas.
     *   R3    las columnas desaparecen. Sólo queda la clave foránea.
     *
     * El desdoblamiento R2.5/R3 no fue burocracia: el despliegue arranca el
     * contenedor nuevo —que corre `migrate --force`— mientras el viejo sigue
     * atendiendo tráfico contra la misma base. Juntar ambos pasos habría dejado
     * al contenedor viejo escribiendo columnas ya inexistentes.
     *
     * PARA REVERTIR no sirve ningún respaldo del espejo: estaba obsoleto desde
     * la R2.5. Las columnas se reconstruyen DESDE EL CATÁLOGO, como hace el
     * `down()` de la migración y documenta docs/RUNBOOK_DESPLIEGUE_R3_TICKETS.md.
     */
    private const CATALOGOS_MIGRADOS = [
        'status'   => ['status_id',   TicketCatalogs::STATUS],
        'priority' => ['priority_id', TicketCatalogs::PRIORITY],
        'category' => ['category_id', TicketCatalogs::CATEGORY],
    ];

    /**
     * PR #2 · Captura del diagnóstico — nombre público => [columna FK, catálogo].
     *
     * Estas cinco columnas existen desde la R1 pero nadie las escribía: no había
     * vocabulario que poner en ellas hasta que el PR #1 sembró el Anexo A.
     *
     * Se exponen con la MISMA forma que `status`/`priority`/`category`: el nombre
     * público es el CÓDIGO en texto (`S01`, `RF`, `AC07`, `R02`) y la columna es
     * la clave foránea. No se inventa un contrato nuevo por id porque el módulo
     * entero —controlador, scopes, API pública, tests— ya habla por código, y
     * mezclar las dos formas obligaría a saber cuál toca en cada campo.
     *
     * `ticket_cause` sirve a la vez a la causa SOSPECHADA y a la CONFIRMADA: el
     * vocabulario es el mismo, lo que cambia es quién lo afirma. Compartir
     * catálogo es lo que permite medir si el diagnóstico inicial acertó.
     */
    private const CATALOGOS_DIAGNOSTICO = [
        'symptom'         => ['symptom_id',          TicketCatalogs::SYMPTOM],
        'suspected_cause' => ['suspected_cause_id',  TicketCatalogs::CAUSE],
        'confirmed_cause' => ['confirmed_cause_id',  TicketCatalogs::CAUSE],
        'solution'        => ['solution_id',         TicketCatalogs::SOLUTION],
        'result'          => ['result_id',           TicketCatalogs::RESULT],
    ];

    /**
     * El ticket NO SE BORRA. Nunca, por ningún camino.
     *
     * El requerimiento del cliente trata el ticket como un **expediente**:
     * «revisión sin alterar el expediente», «los estados y timestamps se
     * conservan sin sobrescritura», «ISPwash será el único expediente y
     * consecutivo oficial». Y desde el PR #3 el ticket sostiene su propia
     * auditoría, que cuelga de él por clave foránea.
     *
     * Quitar la ruta `DELETE` no bastaba: el borrado podía llegar igual desde un
     * comando, un job, una acción masiva futura o un `$ticket->delete()` escrito
     * de buena fe. Esta guardia cubre TODOS esos caminos de una vez, porque
     * Eloquent dispara `deleting` en todos ellos.
     *
     * LO QUE NO CUBRE, y por eso además existe la clave foránea `RESTRICT`:
     * `SupportTicket::where(...)->delete()` no pasa por Eloquent. Contra eso sólo
     * protege la base de datos — el mismo razonamiento de la R1 al declarar los
     * catálogos `ON DELETE RESTRICT`: que sea el motor, y no la disciplina de
     * quien esté de turno, quien impida perder el histórico.
     *
     * PARA EL PR C (archivado): cuando se añada `SoftDeletes`, esta guardia hay
     * que **cambiarla**, no quitarla — `delete()` pasará a ser un UPDATE de
     * `deleted_at` y debe permitirse, mientras que `forceDelete()` debe seguir
     * prohibido. Bloquear `forceDeleting` será entonces lo correcto.
     */
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \RuntimeException(
                'Los tickets no se pueden eliminar: el expediente y su historial deben '
                . 'conservarse. Ver docs/cliente/CNO/DISENO_PERMISOS_Y_ARCHIVADO.md.'
            );
        });
    }

    private static function catalogos(): TicketCatalogs
    {
        return app(TicketCatalogs::class);
    }

    // R3: ya no hay hook `saving`. El que existía rescataba la clave foránea
    // leyéndola de la columna enum, y esa columna ya no existe. Tampoco hace
    // falta: el mutator resuelve el id en toda escritura, y la migración R3
    // aborta si encontrara alguna fila sin resolver.

    // ── Código y etiqueta de catálogo ────────────────────────────────────

    /**
     * `status` sigue siendo el CÓDIGO en texto para todo el que lo consuma
     * —controladores, plantillas de correo, API pública—, pero ya no sale de la
     * columna enum sino del catálogo. Cambia de dónde viene el dato, no lo que
     * el resto de la aplicación ve, y por eso la R2 no rompe ningún contrato.
     *
     * El `?? $value` es la red de la transición: si un ticket todavía no tuviera
     * `status_id` resuelto, se responde con la columna enum en vez de con null.
     * Cuando la R3 elimine esa columna, `$value` será null y el catálogo será la
     * única fuente, sin que haya que tocar esto.
     */
    protected function status(): Attribute
    {
        return $this->atributoDeCatalogo('status');
    }

    protected function priority(): Attribute
    {
        return $this->atributoDeCatalogo('priority');
    }

    protected function category(): Attribute
    {
        return $this->atributoDeCatalogo('category');
    }

    /**
     * OJO — este método NO declara `: Attribute` a propósito, aunque devuelva uno.
     *
     * Eloquent descubre los accessors reflexionando sobre los métodos cuyo tipo
     * de retorno declarado es `Attribute`, y los INVOCA SIN ARGUMENTOS para
     * construir la caché de mutators. Con la firma declarada, este ayudante
     * entraría en esa lista y reventaría con «Too few arguments» en cualquier
     * lectura del modelo. Sin el tipo declarado, queda invisible para esa
     * reflexión y sólo lo llaman los tres accessors de arriba.
     *
     * @return Attribute
     */
    private function atributoDeCatalogo(string $enum)
    {
        [$columna, $tabla] = self::CATALOGOS_MIGRADOS[$enum];

        return Attribute::make(
            // R3: el catálogo es la ÚNICA fuente. Hasta la R2.5 esto llevaba un
            // `?? $value` que caía a la columna enum cuando la clave foránea no
            // estaba resuelta; esa columna ya no existe y el respaldo sobra.
            get: fn () => self::catalogos()->code($tabla, $this->attributes[$columna] ?? null),
            set: fn (?string $code) => [
                $columna => self::catalogos()->id($tabla, $code),
            ],
        );
    }

    // ── Diagnóstico (PR #2) ──────────────────────────────────────────────

    protected function symptom(): Attribute
    {
        return $this->atributoDeDiagnostico('symptom');
    }

    protected function suspectedCause(): Attribute
    {
        return $this->atributoDeDiagnostico('suspected_cause');
    }

    protected function confirmedCause(): Attribute
    {
        return $this->atributoDeDiagnostico('confirmed_cause');
    }

    protected function solution(): Attribute
    {
        return $this->atributoDeDiagnostico('solution');
    }

    protected function result(): Attribute
    {
        return $this->atributoDeDiagnostico('result');
    }

    /**
     * Igual que `atributoDeCatalogo`, con dos diferencias que importan.
     *
     * 1. El `set` resuelve el código EN EL ÁMBITO DEL TENANT del ticket. Los
     *    catálogos de síntoma, causa y acción admiten filas propias de cada ISP,
     *    y dos ISP pueden tener el mismo código; resolver sin tenant devolvería
     *    el primero que saliera de la consulta, que puede ser el del otro.
     * 2. Un código vacío guarda NULL en vez de dejar el campo intacto. Es lo que
     *    permite BORRAR un diagnóstico desde el formulario: sin esto, elegir la
     *    opción «— sin definir —» no tendría forma de deshacer lo anterior.
     *
     * Sobre la ausencia de `: Attribute` en la firma, ver la nota de
     * `atributoDeCatalogo()`: es el mismo motivo, y omitirlo no es un descuido.
     *
     * @return Attribute
     */
    private function atributoDeDiagnostico(string $campo)
    {
        [$columna, $tabla] = self::CATALOGOS_DIAGNOSTICO[$campo];

        return Attribute::make(
            get: fn () => self::catalogos()->code($tabla, $this->attributes[$columna] ?? null),
            set: fn (?string $code) => [
                $columna => self::catalogos()->idParaTenant($tabla, $code, $this->tenantParaResolver()),
            ],
        );
    }

    /**
     * Tenant contra el que resolver un código de diagnóstico.
     *
     * No basta con leer `$this->attributes['tenant_id']`: en un `create()` el
     * mutator corre durante `fill()`, y `fill()` recorre el array EN EL ORDEN EN
     * QUE LLEGAN LAS CLAVES. Si el diagnóstico va antes que `tenant_id` —o si
     * quien crea el ticket confía en que el hook `creating` de BelongsToTenant lo
     * rellene, que corre después—, el tenant todavía no existe y un código propio
     * del ISP no resolvería.
     *
     * El respaldo es el usuario autenticado, exactamente la misma fuente que usa
     * `BelongsToTenant`, así que no introduce una segunda verdad.
     */
    private function tenantParaResolver(): ?int
    {
        $tenantId = $this->attributes['tenant_id'] ?? auth()->user()?->tenant_id;

        return $tenantId === null ? null : (int) $tenantId;
    }

    /**
     * El diagnóstico completo, con código y etiqueta legible.
     *
     * Se devuelve agrupado y no como diez claves sueltas en la raíz del ticket
     * porque son un bloque conceptual: o se está diagnosticando o no. Agrupar
     * también deja sitio para que el PR #3 cuelgue aquí la trazabilidad sin
     * volver a cambiar la forma de la respuesta.
     *
     * Cada campo es `null` cuando no hay diagnóstico —el caso normal de un
     * ticket recién abierto—, nunca un objeto con claves vacías: distinguir
     * «sin diagnosticar» de «diagnosticado en blanco» importa para las métricas
     * del PR #7.
     *
     * @return array<string, array{code: string, label: ?string}|null>
     */
    public function getDiagnosisAttribute(): array
    {
        $salida = [];

        foreach (self::CATALOGOS_DIAGNOSTICO as $campo => [$columna, $tabla]) {
            $id = $this->attributes[$columna] ?? null;

            $salida[$campo] = $id === null ? null : [
                'code'  => self::catalogos()->code($tabla, (int) $id),
                'label' => self::catalogos()->label($tabla, (int) $id),
            ];
        }

        return $salida;
    }

    public function getStatusLabelAttribute(): ?string
    {
        return self::catalogos()->label(TicketCatalogs::STATUS, $this->attributes['status_id'] ?? null);
    }

    public function getPriorityLabelAttribute(): ?string
    {
        return self::catalogos()->label(TicketCatalogs::PRIORITY, $this->attributes['priority_id'] ?? null);
    }

    public function getCategoryLabelAttribute(): ?string
    {
        return self::catalogos()->label(TicketCatalogs::CATEGORY, $this->attributes['category_id'] ?? null);
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('created_at', 'asc');
    }

    public function attachments()
    {
        return $this->hasMany(SupportTicketAttachment::class, 'ticket_id');
    }

    public function charges()
    {
        return $this->hasMany(Invoice::class, 'ticket_id')->orderBy('created_at', 'desc');
    }

    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class, 'sectorial_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────
    //
    // Reciben el CÓDIGO, como siempre, pero filtran por la clave foránea. Un
    // código inexistente resuelve a null y se filtra por `status_id IS NULL`,
    // que no devuelve tickets: preferible a ignorar el filtro y devolverlos
    // todos, que es lo que haría un `where('status', 'inventado')` silencioso.

    public function scopeByStatus($query, $status)
    {
        return $query->where('status_id', self::catalogos()->id(TicketCatalogs::STATUS, $status));
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority_id', self::catalogos()->id(TicketCatalogs::PRIORITY, $priority));
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category_id', self::catalogos()->id(TicketCatalogs::CATEGORY, $category));
    }
}
