<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpArticle extends Model
{
    protected $table = 'help_articles';

    protected $fillable = [
        'category_id',
        'title',
        'content',
        'tips',
        'is_published',
        'display_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(HelpCategory::class, 'category_id');
    }
}
