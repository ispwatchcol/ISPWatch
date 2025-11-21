<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryDevice extends Model
{
    protected $table = 'inventory_device';
    protected $fillable = [
        'id',
        'stock_id',
        'provider_id',
        'user_id',
        'branch_id',
        'serial',
        'mac',
    ];

    public $timestamps = true;

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }

    public function provider()
    {
        return $this->belongsTo(InventoryProvider::class, 'provider_id');
    }

    public function branch()
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }
}
