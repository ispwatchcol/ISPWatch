<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SectorialPhoto extends Model
{
    use BelongsToTenant;

    protected $table = 'sectorial_photo';

    protected $fillable = [
        'sectorial_id',
        'user_id',
        'tenant_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'caption',
    ];

    protected $appends = ['url'];

    public function sectorial()
    {
        return $this->belongsTo(Sectorial::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }
        return asset('storage/' . ltrim($this->file_path, '/'));
    }
}
