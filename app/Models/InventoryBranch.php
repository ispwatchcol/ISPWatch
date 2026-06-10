<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryBranch extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_branch';

    protected $fillable = [
        'name',
        'dir',
        'numero',
    ];
}
