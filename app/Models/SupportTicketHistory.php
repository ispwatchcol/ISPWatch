<?php

namespace App\Models;

use App\Support\AuditContext;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Un evento del expediente de un ticket. PR #3 · F1-17.
 *
 * APPEND-ONLY, Y SE HACE CUMPLIR AQUÍ
 *
 * El requerimiento dice que «la auditoría no debe ser editable desde la
 * operación ordinaria». Eso no se garantiza con disciplina: se garantiza
 * haciendo que `update()` y `delete()` LANCEN. Cualquier código que lo intente
 * —hoy o dentro de dos años— revienta en los tests antes de llegar a producción.
 *
 * No hay endpoints de edición ni de borrado, y no debe haberlos. Corregir un
 * evento equivocado se hace añadiendo otro, como en cualquier libro contable.
 */
class SupportTicketHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'support_ticket_history';

    // ── Tipos de evento ──────────────────────────────────────────────────
    //
    // Constantes y no cadenas sueltas: son el vocabulario que la interfaz
    // traduce y que los tests afirman, y un typo en una cadena no lo detecta
    // nadie hasta que el historial sale vacío.

    public const CREATED           = 'ticket_created';
    public const STATUS            = 'status_changed';
    public const PRIORITY          = 'priority_changed';
    public const CATEGORY          = 'category_changed';
    public const STAFF             = 'staff_changed';
    public const SYMPTOM           = 'symptom_changed';
    public const SUSPECTED_CAUSE   = 'suspected_cause_changed';
    public const CONFIRMED_CAUSE   = 'confirmed_cause_changed';
    public const SOLUTION          = 'solution_changed';
    public const RESULT            = 'result_changed';
    public const NOTE_ADDED        = 'note_added';
    public const ATTACHMENT_ADDED  = 'attachment_added';
    public const CHARGE_CREATED    = 'charge_created';

    protected $fillable = [
        'tenant_id',
        'support_ticket_id',
        'actor_user_id',
        'event_type',
        'field',
        'old_value',
        'new_value',
        'metadata',
        'source',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'El historial del ticket es inalterable: no se puede modificar un evento ya registrado. '
                . 'Para corregir, registra un evento nuevo.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'El historial del ticket es inalterable: no se puede borrar un evento. '
                . 'Los eventos sólo desaparecen si se borra el ticket entero.'
            );
        });
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Registra un evento.
     *
     * EL ACTOR NO SE PASA POR PARÁMETRO A PROPÓSITO. Sale de `AuditContext`,
     * que lo resuelve de la sesión del servidor. Aceptarlo desde fuera sería
     * repetir el fallo que el endurecimiento posterior al PR #2 acaba de
     * corregir en las notas: allí el cliente mandaba `user_id` y podía firmar
     * en nombre de otro.
     *
     * `AuditContext::actorId()` además distingue un `User` de un `ApiClient`:
     * la API pública autentica un ApiClient, cuyo id vive en otra tabla, y
     * meterlo en una columna con clave foránea contra `users` reventaría en
     * PostgreSQL —y pasaría inadvertido en SQLite, que no aplica las foráneas—.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function registrar(
        SupportTicket $ticket,
        string $eventType,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $metadata = null,
    ): self {
        $actorId = AuditContext::actorId();

        return static::create([
            'tenant_id'         => $ticket->tenant_id,
            'support_ticket_id' => $ticket->getKey(),
            'actor_user_id'     => $actorId,
            'event_type'        => $eventType,
            'field'             => $field,
            'old_value'         => $oldValue,
            'new_value'         => $newValue,
            'metadata'          => $metadata,
            // Sin actor humano es el sistema quien actuó: el scheduler, un
            // comando de consola o una automatización. El requerimiento pide
            // distinguir «usuario o aplicación», y ésta es la distinción.
            'source'            => $actorId === null ? 'system' : AuditContext::source(),
        ]);
    }
}
