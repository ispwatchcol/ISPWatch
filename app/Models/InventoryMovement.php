<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una línea del kardex. Append-only: se crea y no se vuelve a tocar.
 *
 * $timestamps está en false a propósito — la tabla no tiene updated_at porque
 * un movimiento no se actualiza jamás. created_at lo pone InventoryLedger.
 */
class InventoryMovement extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_movements';

    public $timestamps = false;

    protected $fillable = [
        'stock_id',
        'device_id',
        'device_serial',
        'type',
        'quantity',
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'installation_id',
        'support_ticket_id',
        'customer_id',
        'notes',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // Tipos de movimiento.
    public const TYPE_ENTRADA     = 'entrada';      // alta / compra
    public const TYPE_TRASPASO    = 'traspaso';     // cambio de custodio
    public const TYPE_INSTALACION = 'instalacion';  // se fue con el cliente
    public const TYPE_DEVOLUCION  = 'devolucion';   // volvió del cliente
    public const TYPE_BAJA        = 'baja';         // dañado / perdido
    // Corrección de una entrada que se digitó de más. Sale del saldo igual que
    // una baja, pero NO es una pérdida: separarlas evita que un error de
    // digitación se cuente como material dañado o perdido.
    public const TYPE_AJUSTE      = 'ajuste';

    /** Todos los tipos, en el orden en que se ofrecen como filtro. */
    public const TYPES = [
        self::TYPE_ENTRADA,
        self::TYPE_TRASPASO,
        self::TYPE_INSTALACION,
        self::TYPE_DEVOLUCION,
        self::TYPE_BAJA,
        self::TYPE_AJUSTE,
    ];

    // Extremos de un movimiento.
    public const HOLDER_BRANCH   = 'branch';
    public const HOLDER_USER     = 'user';
    public const HOLDER_CUSTOMER = 'customer';
    public const HOLDER_SUPPLIER = 'supplier';
    public const HOLDER_SCRAP    = 'scrap';

    public function stock()
    {
        return $this->belongsTo(InventoryStock::class, 'stock_id');
    }

    public function device()
    {
        return $this->belongsTo(InventoryDevice::class, 'device_id');
    }

    public function installation()
    {
        return $this->belongsTo(CustomerInstallation::class, 'installation_id');
    }

    /**
     * Ticket que originó el movimiento. withTrashed porque un ticket archivado
     * no deja de ser la causa de la salida: el kardex tiene que poder nombrarla.
     */
    public function supportTicket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
