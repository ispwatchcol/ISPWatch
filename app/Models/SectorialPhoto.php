<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SectorialPhoto extends Model
{
    use BelongsToTenant;

    protected $table = 'sectorial_photo';

    protected $fillable = [
        'sectorial_id',
        'user_id',
        'tenant_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'caption',
    ];

    protected $appends = ['url'];

    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Endpoint autenticado que entrega la foto (P-40 / KAN-96).
     *
     * Antes era `asset('storage/…')`: una URL pública y sin sesión, sobre un
     * disco efímero y sin `storage:link`, así que la foto salía rota tras cada
     * despliegue y, mientras existía, la leía cualquiera que acertara la ruta.
     * Ver SectorialPhotoController::show().
     */
    public function getUrlAttribute(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }
        return url("/api/sectorials/{$this->sectorial_id}/photos/{$this->id}");
    }
}
