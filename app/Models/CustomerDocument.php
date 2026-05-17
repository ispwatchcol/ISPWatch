<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDocument extends Model
{
    protected $table = 'customer_documents';

    public const TYPES = ['cedula', 'instalacion', 'contrato', 'otros'];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'type',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'signed',
        'uploaded_by',
    ];

    protected $casts = [
        'signed' => 'boolean',
        'file_size' => 'integer',
    ];

    protected $appends = ['url'];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }
}
