<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryProvider extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_provider';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'addr',
        'city',
        'identification',
        'advisor_name',
        'advisor_phone',
        'advisor_email',
        'advisor_position',
    ];
}
