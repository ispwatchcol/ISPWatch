<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketAttachment extends Model
{
    protected $table = 'support_ticket_attachment';
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type'
    ];

    protected $appends = ['url', 'download_url'];

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
