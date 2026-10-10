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
        'mime_type',
        // PR F1 - los tres datos que la seccion 14 pide para la evidencia y que
        // esta tabla no tenia: «Tipo, archivo, fecha, usuario, descripcion e
        // intervencion relacionada». Archivo, fecha y usuario ya estaban.
        'intervention_id',
        'evidence_type',
        'description',
    ];

    /**
     * `file_path` NO sale en el JSON.
     *
     * Es la ruta interna dentro del bucket privado. No le sirve a nadie del otro
     * lado —el archivo se pide por `url` / `download_url`, que pasan por el
     * endpoint autenticado— y publicarla describe la organizacion del
     * almacenamiento a quien no tiene por que conocerla. Se oculta al enlazar la
     * evidencia a la intervencion porque es cuando este modelo empieza a
     * viajar anidado y en mas sitios.
     */
    protected $hidden = ['file_path'];

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

    /**
     * La visita que produjo esta evidencia, si vino de una (seccion 14).
     *
     * NULL es normal y valido: la evidencia que manda el cliente al abrir
     * el ticket no pertenece a ninguna intervencion.
     */
    public function intervention()
    {
        return $this->belongsTo(TicketIntervention::class, 'intervention_id');
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
