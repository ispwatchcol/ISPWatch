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
     * Las etiquetas del catálogo viajan en el JSON del panel junto al código.
     * El frontend ya no las deduce: las recibe.
     */
    protected $appends = ['status_label', 'priority_label', 'category_label'];

    /**
     * FASE 1 · R2 — la clave foránea es la fuente de verdad; el enum es espejo.
     *
     * Columna enum => [columna de clave foránea, tabla de catálogo].
     *
     * En la R1 la dirección era la contraria: se escribía el enum y la FK se
     * rellenaba a partir de él. Ahora se invierte —se resuelve el id y el enum
     * se mantiene al día como COPIA— y con eso ningún lector de la aplicación
     * necesita ya la columna enum, que es exactamente la condición que habilita
     * la R3.
     *
     * El espejo se conserva a propósito mientras la R3 no se apruebe: es lo que
     * permite revertir sin pérdida de datos. Se escribe desde el modelo y no
     * desde un trigger de PostgreSQL para que la suite lo cubra; un trigger
     * sólo existiría en producción, que es donde no se puede probar.
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
     * Reconcilia ambas representaciones antes de guardar.
     *
     * Cubre el caso que los accessors no ven: escribir `status_id` directamente
     * (una asignación masiva, una importación). Manda la FK, y el enum la sigue.
     */
    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            foreach (self::CATALOGOS_MIGRADOS as $enum => [$columna, $tabla]) {
                if ($ticket->isDirty($columna)) {
                    $ticket->attributes[$enum] = self::catalogos()->code($tabla, $ticket->{$columna});

                    continue;
                }

                // Fila anterior al backfill, o creada sin pasar por el mutator.
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
            // Devolver un array escribe las dos columnas de una sola vez: la FK
            // (fuente de verdad) y el espejo, que es lo que mantiene viable la
            // reversión mientras la R3 no se haya aprobado.
            set: fn (?string $code) => [
                $enum    => $code,
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
