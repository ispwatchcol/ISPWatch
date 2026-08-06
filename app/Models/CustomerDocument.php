<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CustomerDocument extends Model
{
    protected $table = 'customer_documents';

    public const TYPES = ['cedula', 'instalacion', 'contrato', 'otros'];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'installation_id',
        'type',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'signed',
        'contract_number',
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

    /**
     * Signed, time-limited URL — the bucket is private, so a permanent
     * public URL is not an option for cedulas / contratos firmados.
     */
    public function getUrlAttribute(): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $this->file_path,
            now()->addMinutes(30)
        );
    }
}
