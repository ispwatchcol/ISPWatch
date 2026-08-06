<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'customer_additional_service_id',
        'type',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'amount'
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Asignación de la que salió este ítem, si vino de un servicio adicional
     * recurrente. Null en todos los demás: plan mensual, instalación, arrastre
     * y cargos puntuales no tienen una asignación detrás.
     */
    public function additionalService()
    {
        return $this->belongsTo(CustomerAdditionalService::class, 'customer_additional_service_id');
    }
}
