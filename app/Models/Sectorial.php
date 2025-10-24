<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sectorial extends Model
{
    protected $table = 'sectorial';
    protected $fillable = [
        'name', 
        'type', 
        'user_rb', 
        'pass_rb', 
        'zona_id', 
        'frequency', 
        'node_tower', 
        'comments'
    ];
}
