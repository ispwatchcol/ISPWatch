<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SectorialHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'sectorial_history';
    public $timestamps = false;

    protected $fillable = [
        'sectorial_id',
        'user_id',
        'tenant_id',
        'action',
        'description',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function log(int $sectorialId, string $action, string $description, ?array $metadata = null, ?int $userId = null, ?int $tenantId = null): self
    {
        return self::create([
            'sectorial_id' => $sectorialId,
            'user_id'      => $userId ?? auth()->id(),
            'tenant_id'    => $tenantId ?? auth()->user()?->tenant_id,
            'action'       => $action,
            'description'  => $description,
            'metadata'     => $metadata,
            'created_at'   => now(),
        ]);
    }
}
