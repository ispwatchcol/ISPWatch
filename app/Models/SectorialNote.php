<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SectorialNote extends Model
{
    use BelongsToTenant;

    protected $table = 'sectorial_note';

    protected $fillable = [
        'sectorial_id',
        'user_id',
        'tenant_id',
        'title',
        'content',
    ];

    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
