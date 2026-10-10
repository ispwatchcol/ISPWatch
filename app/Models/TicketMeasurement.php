<?php

namespace App\Models;

use App\Support\TicketMeasurements;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una medición técnica del ticket (§ 12 de la Solicitud Maestra).
 *
 * Las seis columnas que el documento exige —tipo de prueba, resultado, unidad,
 * fecha/hora, origen y fase— más de qué visita salió y quién la tomó.
 *
 * NO USA `SoftDeletes`, IGUAL QUE `TicketIntervention`
 *
 * Una medición es la constancia de lo que se leyó en un momento. Borrarla, aun
 * blandamente, dejaría el expediente diciendo que nunca se midió. Y eso vacía
 * una regla de cierre: el § 15.5 exige «prueba final o justificación», así que
 * poder hacer desaparecer la prueba equivale a poder saltarse el requisito sin
 * que conste.
 *
 * No hay endpoint de borrado y `deleting` queda bloqueado aquí, para que tampoco
 * lo haya desde un comando de consola.
 *
 * SÍ SE PUEDE CORREGIR, Y ES DISTINTO
 *
 * Un dígito mal tecleado no es lo mismo que una medición que no existió. Se
 * puede editar mientras el ticket no esté cerrado, y cada corrección deja
 * `measurement_updated` en el historial con el valor anterior y el nuevo. El
 * cerrojo no es un estado de la medición —como en las intervenciones— sino el
 * del ticket: una vez cerrado, el expediente no se retoca.
 */
class TicketMeasurement extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_measurement';

    protected $fillable = [
        'tenant_id',
        'support_ticket_id',
        'intervention_id',
        'test_type',
        'value',
        'unit',
        'measured_at',
        'source',
        'phase',
        'recorded_by',
        'recorded_by_name',
    ];

    protected $casts = [
        'measured_at' => 'datetime',
    ];

    protected $appends = ['phase_label'];

    protected static function booted(): void
    {
        static::deleting(function (self $medicion) {
            throw new \RuntimeException(
                'Una medición no se borra: es la constancia de lo que se leyó, y el § 15.5 '
                . 'la convierte en requisito de cierre. Si el valor está mal, corrígelo '
                . 'mientras el ticket siga abierto; la corrección queda en el historial.'
            );
        });
    }

    /** Etiqueta legible de la fase, para que la interfaz no la escriba a mano. */
    public function getPhaseLabelAttribute(): string
    {
        return TicketMeasurements::fases()[$this->phase] ?? $this->phase;
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(TicketIntervention::class, 'intervention_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * ¿Tiene este ticket alguna medición final?
     *
     * Es la mitad de la regla 5 del § 15 —«exigir prueba final o
     * justificación»— y la consulta se hace en cada propuesta y en cada cierre,
     * de ahí el índice `(support_ticket_id, phase)`.
     */
    public static function tienePruebaFinal(int $ticketId): bool
    {
        return static::where('support_ticket_id', $ticketId)
            ->where('phase', TicketMeasurements::FASE_FINAL)
            ->exists();
    }
}
