<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Progreso de una corrida de aprovisionamiento masivo asíncrono.
 *
 * No lleva el global scope de tenant a propósito: los jobs en cola corren sin
 * sesión autenticada y necesitan leer/escribir el registro libremente. El
 * scoping por tenant se hace explícito en el controlador al consultar el estado.
 */
class BulkProvisionRun extends Model
{
    protected $table = 'bulk_provision_runs';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'customer_id',
        'status',
        'total',
        'processed',
        'success_count',
        'fail_count',
        'pppoe_skipped_count',
        'results',
        'finished_at',
    ];

    protected $casts = [
        'results' => 'array',
        'finished_at' => 'datetime',
    ];
}
