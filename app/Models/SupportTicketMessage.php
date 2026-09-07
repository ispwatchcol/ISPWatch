<?php

namespace App\Models;

use App\Support\AuthorSnapshot;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $table = 'support_ticket_message';

    protected $fillable = ['ticket_id', 'user_id', 'author_name', 'message', 'is_internal'];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    protected $appends = ['author_label'];

    /**
     * Congela el nombre del autor al escribir la nota.
     *
     * Va en el modelo y no en el controlador para que valga en TODOS los
     * caminos: el panel, un comando, un job o una carga futura. Si alguno
     * olvidara ponerlo, la nota nacería sin autor visible y al darse de baja el
     * usuario ya no habría de dónde recuperarlo.
     */
    protected static function booted(): void
    {
        static::creating(function (self $nota): void {
            if ($nota->author_name === null) {
                $nota->author_name = AuthorSnapshot::para($nota->user_id);
            }
        });
    }

    /**
     * Nombre a mostrar, resistente a que el usuario ya no exista.
     *
     * Prefiere el usuario vivo —puede haberse corregido un apellido— y cae al
     * nombre congelado. Sólo si no hay ninguno de los dos dice «Usuario
     * eliminado», que es información, no un hueco.
     */
    public function getAuthorLabelAttribute(): string
    {
        if ($this->relationLoaded('user') && $this->user) {
            $vivo = trim(($this->user->user_name ?? '') . ' ' . ($this->user->user_lastname ?? ''));

            if ($vivo !== '') {
                return $vivo;
            }
        }

        return $this->author_name ?? 'Usuario eliminado';
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
