<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScriptVersion extends Model
{
    protected $table = 'script_version';

    protected $fillable = [
        'version',
    ];

    public $timestamps = false;
}
