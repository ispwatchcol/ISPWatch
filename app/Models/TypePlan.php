<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypePlan extends Model
{
    protected $table = 'type_plans';

    protected $fillable = [
        'name',
    ];

    public function plans()
    {
        return $this->hasMany(Plan::class, 'type_plan_id');
    }
}