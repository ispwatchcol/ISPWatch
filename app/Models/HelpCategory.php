<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpCategory extends Model
{
    protected $table = 'help_categories';

    protected $fillable = [
        'name',
        'icon',
        'description',
        'display_order',
    ];

    public function articles()
    {
        return $this->hasMany(HelpArticle::class, 'category_id')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function publishedArticles()
    {
        return $this->articles()->where('is_published', true);
    }
}
