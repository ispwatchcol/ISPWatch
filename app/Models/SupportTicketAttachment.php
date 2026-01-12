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

    protected $appends = ['url'];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper para obtener URL pública
    public function getUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }
}
