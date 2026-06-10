<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_stock';

    // tenant_id is intentionally NOT fillable: it is set automatically from the
    // authenticated user by BelongsToTenant, so a client can't spoof it.
    protected $fillable = [
        'brand',
        'model',
        'desc',
        'price',
    ];
}
