<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CutType extends Model
{
    protected $table = 'cut_type';

    protected $fillable = [
        'name',
        'description',
    ];

    public $timestamps = false;

    public function routers()
    {
        return $this->hasMany(Router::class, 'cut_type_id');
    }
}
