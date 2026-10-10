<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una visita o atención remota registrada sobre un ticket (§ 14).
 *
 * NO USA `SoftDeletes`, Y ES DELIBERADO
 *
 * El ticket sí lo usa, porque archivar un expediente entero es una operación de
 * negocio reversible y auditada (PR C). Una intervención no: es el registro de
 * que alguien fue, miró y actuó. Marcarla como borrada la sacaría del expediente
 * sin que el histórico contara por qué, y el § 15.10 lo prohíbe — «El cierre no
 * debe borrar la causa sospechada, las intervenciones ni los estados
 * anteriores».
 *
 * Tampoco hay borrado físico: no existe endpoint, y `deleting` queda bloqueado
 * más abajo para que tampoco lo haya por accidente desde un comando o un test.
 *
 * EL CERROJO DE EDICIÓN
 *
 *   `finished_at` NULL  → en curso: se puede editar.
 *   `finished_at` lleno → cerrada: NO se puede editar.
 *
 * Corregir una cerrada exige reabrirla, lo que pide motivo y deja
 * `intervention_reopened` en el historial del ticket. El cerrojo se comprueba en
 * el controlador —que es quien puede devolver un 422 con explicación— y se
 * repite aquí en `saving` como red: un camino nuevo que no pase por el
 * controlador no debe poder saltárselo en silencio.
 */
class TicketIntervention extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_intervention';

    /** § 14: «tipo remoto o presencial». No hay un tercero (decisión S-2). */
    public const KIND_REMOTO     = 'remoto';
    public const KIND_PRESENCIAL = 'presencial';

    public const KINDS = [self::KIND_REMOTO, self::KIND_PRESENCIAL];

    protected $fillable = [
        'tenant_id',
        'support_ticket_id',
        'sequence',
        'kind',
        'technician_id',
        'assistant_id',
        'technician_name',
        'assistant_name',
        'started_at',
        'finished_at',
        'finding',
        'action_taken',
        'outcome',
        'next_step',
        'created_by',
    ];

    protected $casts = [
        'sequence'    => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $appends = ['is_open'];

    protected static function booted(): void
    {
        // Sin endpoint de borrado, pero la prohibición vive también aquí: el
        // expediente no pierde visitas ni por un comando de consola ni por un
        // `delete()` despistado en un test. Mismo criterio que
        // `SupportTicketHistory`, que bloquea `updating` y `deleting`.
        static::deleting(function (self $intervencion) {
            throw new \RuntimeException(
                'Una intervención no se borra. Si el registro es incorrecto, reábrela '
                . '(POST /api/support/{ticket}/interventions/{id}/reopen) y corrígela: '
                . 'así la corrección queda en el historial.'
            );
        });

        // Red de seguridad del cerrojo. El controlador ya lo comprueba y da un
        // 422 explicado; esto cubre cualquier otro camino de escritura.
        static::saving(function (self $intervencion) {
            if (!$intervencion->exists) {
                return;
            }

            $estabaCerrada = $intervencion->getOriginal('finished_at') !== null;

            // Cerrar una intervención en curso es legítimo. Lo que no se admite
            // es tocar el contenido de una que YA estaba cerrada.
            if (!$estabaCerrada) {
                return;
            }

            $camposDeContenido = ['kind', 'technician_id', 'assistant_id', 'started_at',
                'finding', 'action_taken', 'outcome', 'next_step'];

            if (array_intersect($camposDeContenido, array_keys($intervencion->getDirty()))) {
                throw new \RuntimeException(
                    'Una intervención finalizada no se edita. Reábrela primero.'
                );
            }
        });
    }

    /** ¿Sigue en curso? Es el dato que la interfaz necesita para decidir. */
    public function getIsOpenAttribute(): bool
    {
        return $this->finished_at === null;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    /**
     * Evidencia producida en esta visita.
     *
     * Son filas de `support_ticket_attachment`, no una tabla aparte: el archivo
     * ya está en el bucket privado y ya se sirve por un endpoint que comprueba
     * tenant y ticket. Duplicarlo daría dos copias del mismo byte y dos sitios
     * donde comprobar permisos.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'intervention_id');
    }
}
