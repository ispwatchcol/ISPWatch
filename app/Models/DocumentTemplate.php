<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    use BelongsToTenant;

    protected $table = 'document_templates';

    const TYPE_INVOICE = 'invoice';
    const TYPE_CONTRACT = 'contract';
    const TYPE_INSTALLATION = 'installation';

    const TYPES = [self::TYPE_INVOICE, self::TYPE_CONTRACT, self::TYPE_INSTALLATION];

    /**
     * Tamaños/orientaciones aceptados por dompdf que exponemos al tenant.
     * Los defaults reproducen el comportamiento previo a la columna (el
     * default de config/dompdf.php), así que una plantilla sin estos valores
     * sale idéntica a como salía antes. Ver TemplateRenderer::applyPaper().
     */
    const PAGE_SIZES = ['a4', 'letter', 'legal'];
    const PAGE_ORIENTATIONS = ['portrait', 'landscape'];

    const DEFAULT_PAGE_SIZE = 'a4';
    const DEFAULT_PAGE_ORIENTATION = 'portrait';

    protected $fillable = [
        'tenant_id',
        'type',
        'body_html',
        'is_active',
        'is_advanced_mode',
        'page_size',
        'page_orientation',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_advanced_mode' => 'boolean',
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
