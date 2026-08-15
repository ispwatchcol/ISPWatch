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
     * FASE 1 · R2.5 — `status`, `priority` y `category` se DECLARAN aquí.
     *
     * Hoy siguen siendo columnas reales, así que aparecerían en el JSON de todos
     * modos y esto no cambia nada visible. Se adelanta a propósito: cuando la R3
     * elimine esas columnas dejarán de estar en `$attributes`, y sin `$appends`
     * **desaparecerían silenciosamente de la respuesta** —comprobado ejecutando,
     * no por inspección—. Declararlo ahora convierte la R3 en una migración
     * limpia, sin riesgo de serialización, y deja el comportamiento fijado por
     * la suite desde antes.
     */
    protected $appends = [
        'status', 'priority', 'category',
        'status_label', 'priority_label', 'category_label',
    ];

    /**
     * FASE 1 · R2.5 — la clave foránea es la ÚNICA representación que se escribe.
     *
     * Columna enum => [columna de clave foránea, tabla de catálogo].
     *
     * Historia de las tres fases, porque el sentido de la flecha cambió dos veces:
     *
     *   R1   se escribía el enum y la FK se rellenaba a partir de él.
     *   R2   se invirtió: la FK pasó a mandar y el enum se mantenía como copia.
     *   R2.5 se deja de escribir la copia. Las columnas siguen existiendo pero
     *        quedan CONGELADAS en su último valor conocido.
     *
     * Por qué existe este paso intermedio y no se hizo junto con la R3: el
     * despliegue de App Platform arranca el contenedor nuevo —que corre
     * `migrate --force`— mientras el viejo sigue atendiendo tráfico contra la
     * misma base. Si la migración que elimina las columnas entrara en el mismo
     * despliegue que el código que deja de escribirlas, durante esa ventana el
     * contenedor viejo intentaría escribir columnas ya inexistentes y toda
     * escritura de ticket fallaría. Separándolo, cuando la R3 dropee las
     * columnas ya no habrá código vivo que las toque.
     *
     * OJO CON REVERTIR: desde este punto el espejo está obsoleto y NO se puede
     * usar para restaurar. El rollback debe reconstruirlo desde el catálogo
     * (`UPDATE … FROM ticket_status`), como documenta el runbook de la R3.
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

    /**
     * Red de seguridad de la transición: resolver la FK de una fila que llegue
     * sin ella.
     *
     * No debería ocurrir —la migración R1 rellenó todo y aborta si queda algún
     * huérfano, y desde entonces el mutator la resuelve siempre—, pero mientras
     * la columna enum exista es un rescate gratis. Desaparece con la R3, junto
     * con la columna de la que lee.
     *
     * Lo que este hook YA NO hace es escribir el espejo: esa es exactamente la
     * diferencia entre la R2 y la R2.5.
     */
    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            foreach (self::CATALOGOS_MIGRADOS as $enum => [$columna, $tabla]) {
                if ($ticket->{$columna} === null && ($ticket->attributes[$enum] ?? null) !== null) {
                    $ticket->{$columna} = self::catalogos()->id($tabla, $ticket->attributes[$enum]);
                }
            }
        });
    }

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
            get: fn ($value) => self::catalogos()->code($tabla, $this->attributes[$columna] ?? null) ?? $value,
            // R2.5: se escribe SÓLO la clave foránea. Antes esto devolvía además
            // `$enum => $code` para mantener el espejo; dejar de hacerlo es todo
            // el cambio de esta fase, y lo que permite que la R3 pueda eliminar
            // esas columnas sin que ningún código vivo intente escribirlas.
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
