<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por petición atendida (o rechazada) en /api/v1/partner/*.
 *
 * Sin `updated_at`: es un registro append-only, nunca se modifica.
 */
class ApiKeyRequestLog extends Model
{
    use BelongsToTenant;

    protected $table = 'api_key_request_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'api_client_id',
        'token_id',
        'tenant_id',
        'method',
        'path',
        'ip',
        'status_code',
        'duration_ms',
        'denied_reason',
    ];

    protected $casts = [
        'created_at'  => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'api_client_id');
    }
}
