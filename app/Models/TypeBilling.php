<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeBilling extends Model
{
    protected $table = 'type_billing';

    protected $fillable = [
        'type',
    ];

    public $timestamps = false;
}
