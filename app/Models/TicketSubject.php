<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Asunto frecuente del ticket de soporte («Sin internet», «Traslado»…).
 *
 * Es vocabulario PROPIO de cada ISP: no hay filas de plataforma. Se elige al
 * radicar y al cerrar el ticket, y es lo que permite filtrar el listado por
 * asunto — el texto libre que había antes lo hacía imposible.
 *
 * Un asunto que ya usaron tickets no se borra: se retira (`is_active = false`)
 * y deja de ofrecerse, pero los tickets viejos siguen agrupados bajo él.
 */
class TicketSubject extends Model
{
    use BelongsToTenant;

    protected $table = 'ticket_subjects';

    protected $fillable = [
        'tenant_id',
        'name',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function tickets()
    {
        return $this->hasMany(SupportTicket::class, 'subject_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
