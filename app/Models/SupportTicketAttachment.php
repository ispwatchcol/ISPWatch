<?php

namespace App\Models;

use App\Support\AuthorSnapshot;
use Illuminate\Database\Eloquent\Model;

class SupportTicketAttachment extends Model
{
    protected $table = 'support_ticket_attachment';
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'author_name',
        'file_name',
        'file_path',
        'file_size',
        'mime_type'
    ];

    protected $appends = ['url', 'download_url', 'author_label'];

    /**
     * Congela el nombre de quien subió el archivo.
     *
     * Igual que en las notas: va en el modelo para cubrir todos los caminos de
     * escritura, no sólo el controlador.
     */
    protected static function booted(): void
    {
        static::creating(function (self $adjunto): void {
            if ($adjunto->author_name === null) {
                $adjunto->author_name = AuthorSnapshot::para($adjunto->user_id);
            }
        });
    }

    /** Quién lo subió, resistente a que ese usuario ya no exista. */
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

    /**
     * Vista previa, por endpoint autenticado.
     *
     * Antes esto era `asset('storage/'.$file_path)`: una URL PÚBLICA. Cualquiera
     * con la ruta —que es adivinable, `support_attachments/{ticket}/…`— leía el
     * adjunto de otro ISP sin sesión. Y encima no funcionaba: App Platform no
     * ejecuta `storage:link` y su disco es efímero, así que la imagen salía rota.
     *
     * El endpoint comprueba el tenant en cada petición. El navegador manda la
     * cookie de sesión solo con poner la URL en un `<img src>`, porque el cliente
     * de la SPA ya trabaja con `withCredentials`.
     */
    public function getUrlAttribute(): string
    {
        return url("/api/support/{$this->ticket_id}/attachments/{$this->id}");
    }

    /** Misma autorización, pero forzando descarga. */
    public function getDownloadUrlAttribute(): string
    {
        return url("/api/support/{$this->ticket_id}/attachments/{$this->id}/download");
    }
}
