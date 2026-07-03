<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One "falla masiva" (mass outage) event for a core/router. Append-only: an
 * outage broadcast and its later resolution are two separate rows. Converza
 * polls these read-only to fan out the WhatsApp notice to the core's customers.
 *
 * @see database/migrations/2026_07_03_010000_create_router_outage_events_table.php
 */
class RouterOutageEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'router_outage_events';

    /** Core just went into a mass failure. */
    public const TYPE_OUTAGE = 'outage';

    /** Core recovered / service restored. */
    public const TYPE_RESTORED = 'restored';

    public const TYPES = [self::TYPE_OUTAGE, self::TYPE_RESTORED];

    // Append-only: only created_at is meaningful (updated_at would be a lie).
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'router_id',
        'type',
        'affected_count',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'affected_count' => 'integer',
        'created_at'     => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class, 'router_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
