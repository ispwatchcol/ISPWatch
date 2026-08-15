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
