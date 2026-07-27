<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $table = 'document_templates';

    const TYPE_INVOICE = 'invoice';
    const TYPE_CONTRACT = 'contract';
    const TYPE_INSTALLATION = 'installation';

    const TYPES = [self::TYPE_INVOICE, self::TYPE_CONTRACT, self::TYPE_INSTALLATION];

    protected $fillable = [
        'tenant_id',
        'type',
        'body_html',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
