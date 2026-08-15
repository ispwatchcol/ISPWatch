<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
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
        'resolved_at'
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    /**
     * FASE 1 · R1 — relleno hacia adelante de las columnas de catálogo.
     *
     * Columna enum => [columna de clave foránea, tabla de catálogo].
     *
     * La migración R1 rellenó las filas que YA existían. Sin esto, todo ticket
     * creado a partir de ese momento nacería con `status_id` en nulo y el
     * invariante «ningún ticket sin catálogo» se rompería el mismo día del
     * despliegue, dejando un agujero que habría que volver a rellenar en la R2.
     *
     * Ojo con lo que esto NO es: el enum sigue siendo la fuente de verdad y
     * nadie lee todavía por clave foránea. Esto sólo mantiene la columna nueva
     * al día mientras convive con la vieja. Invertir esa dirección —leer y
     * escribir por catálogo— es la R2, y va aparte.
     */
    private const CATALOGOS_MIGRADOS = [
        'status'   => ['status_id',   'ticket_status'],
        'priority' => ['priority_id', 'ticket_priority'],
        'category' => ['category_id', 'ticket_category'],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            foreach (self::CATALOGOS_MIGRADOS as $enum => [$columna, $tabla]) {
                // Se recalcula cuando cambia el enum o cuando la columna nueva
                // viene vacía (ticket nuevo, o fila anterior al backfill).
                if (!$ticket->isDirty($enum) && $ticket->{$columna} !== null) {
                    continue;
                }

                $ticket->{$columna} = $ticket->{$enum} === null
                    ? null
                    : \Illuminate\Support\Facades\DB::table($tabla)
                        ->where('code', $ticket->{$enum})
                        ->value('id');
            }
        });
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

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
