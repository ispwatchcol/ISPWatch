<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\FreezesCustomerSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Model
{
    use HasFactory, BelongsToTenant, FreezesCustomerSnapshot;

    const TYPE_MONTHLY       = 'monthly';
    const TYPE_SERVICE_CHARGE = 'service_charge';
    const TYPE_ADDITIONAL    = 'additional';
    const TYPE_INSTALLATION  = 'installation';

    // ── Estados ──────────────────────────────────────────────────────────
    //
    // Los siete del CHECK de `invoices.status`, que existe desde la migración
    // del módulo (2026-01-13). Se declaran aquí porque hasta ahora viajaban
    // como cadenas sueltas repartidas por una docena de consultas, y un typo en
    // una de ellas no lo detecta nadie: la factura simplemente deja de contar.

    const STATUS_DRAFT     = 'draft';
    const STATUS_ISSUED    = 'issued';
    const STATUS_PAID      = 'paid';
    const STATUS_PARTIAL   = 'partial';
    const STATUS_OVERDUE   = 'overdue';

    /**
     * Anulada. Es el estado al que lleva la anulación.
     *
     * `void` y no `cancelled` porque es el que el sistema ya escribía —
     * `VoidCourtesyInvoices` deja así las facturas de cortesía— y el que
     * `SupportTicketController` consulta para decidir si un ticket con cargos
     * puede archivarse.
     */
    const STATUS_VOID      = 'void';

    /**
     * Equivalente histórico de `void`. NINGÚN código lo escribe hoy salvo el
     * `PUT` genérico de facturas, que era justamente el agujero que la
     * anulación cierra. Se sigue TRATANDO como anulada en todas las lecturas
     * —está en cada `whereNotIn` del módulo— para no reinterpretar filas que ya
     * existan con ese valor.
     */
    const STATUS_CANCELLED = 'cancelled';

    /** Los dos que significan «esto ya no se cobra». */
    const ESTADOS_ANULADOS = [self::STATUS_VOID, self::STATUS_CANCELLED];

    /**
     * Estados en los que la factura ya salió al mundo: tiene valor contable y
     * no puede destruirse, sólo anularse.
     */
    const ESTADOS_EMITIDOS = [
        self::STATUS_ISSUED,
        self::STATUS_PAID,
        self::STATUS_PARTIAL,
        self::STATUS_OVERDUE,
    ];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        // Titular congelado (P-43). La factura sobrevive al borrado del cliente
        // y estos dos campos son lo único que permite seguir atribuyéndola.
        'customer_name',
        'customer_document',
        'service_id',
        'invoice_type',
        'ticket_id',
        'installation_id',
        'number',
        'issue_date',
        'due_date',
        'period_start',
        'period_end',
        'currency',
        'subtotal',
        'tax',
        'total',
        'balance_due',
        'carried_in',
        'carried_out',
        'status',
        'notes',
        'last_reminder_sent',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'carried_in' => 'decimal:2',
        'carried_out' => 'decimal:2',
        'last_reminder_sent' => 'datetime',
    ];

    /**
     * La cédula congelada no sale en el JSON. Mismo criterio que en `Payment`:
     * el listado de facturas carga el perfil del cliente pidiendo sólo
     * `user_id,name,last_name`, y `customer_document` habría colado la cédula
     * de cada titular en una respuesta que la evitaba a propósito.
     *
     * `$hidden` sólo afecta a la serialización: el PDF la sigue leyendo.
     */
    protected $hidden = ['customer_document'];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments()
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * Asignaciones de pago contra esta factura. Existe además de payments()
     * porque para auditar hace falta sumar los montos asignados sin cargar los
     * pagos: una factura `paid` cuya suma de asignaciones no llega al total fue
     * saldada con saldo a favor.
     */
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function installation()
    {
        return $this->belongsTo(CustomerInstallation::class, 'installation_id');
    }

    /** Arrastres que ESTA factura generó al cerrarse con un abono parcial. */
    public function carryoversOut()
    {
        return $this->hasMany(InvoiceCarryover::class, 'from_invoice_id');
    }

    /** Arrastres de facturas anteriores que ESTA factura está cobrando. */
    public function carryoversIn()
    {
        return $this->hasMany(InvoiceCarryover::class, 'to_invoice_id');
    }

    /**
     * Tipo del catálogo. Se enlaza por slug (no por FK) porque invoice_type es
     * texto libre histórico y los tipos del sistema son globales (tenant_id
     * NULL): la relación tendría que mirar dos columnas.
     */
    public function type()
    {
        return $this->belongsTo(InvoiceType::class, 'invoice_type', 'slug');
    }

    /**
     * Quién anuló la factura.
     *
     * `ON DELETE SET NULL` en la base: dar de baja a ese usuario deja el
     * vínculo vacío, no borra la factura. Quién fue queda además en
     * `audit_logs`, que es la fuente que no se puede tocar desde la operación.
     */
    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** ¿Está anulada? Cubre los dos estados, no sólo `void`. */
    public function estaAnulada(): bool
    {
        return in_array($this->status, self::ESTADOS_ANULADOS, true);
    }

    /**
     * ¿Se puede DESTRUIR esta factura?
     *
     * Sólo un borrador que no salió nunca: sin número asignado y sin ticket
     * detrás. Cualquier otra cosa se anula.
     *
     * En la práctica esto no es cierto de ninguna factura del sistema: toda
     * ruta de creación llama a `generateInvoiceNumber()` y deja el estado en
     * `issued`. `draft` es el valor por defecto de la columna y nada lo escribe.
     * La comprobación se implementa igualmente porque la política de borradores
     * puede cambiar y porque el valor por defecto sigue ahí: un `INSERT` a mano
     * puede producir uno.
     */
    public function sePuedeBorrar(): bool
    {
        return $this->status === self::STATUS_DRAFT
            && blank($this->number)
            && $this->ticket_id === null;
    }

    /**
     * Motivo por el que NO se puede borrar, para poder decírselo a quien lo
     * intenta. `null` si sí se puede.
     */
    public function porQueNoSePuedeBorrar(): ?string
    {
        if ($this->sePuedeBorrar()) {
            return null;
        }

        if ($this->estaAnulada()) {
            return 'Esta factura ya está anulada y se conserva como registro contable. '
                . 'Una factura anulada es de sólo lectura.';
        }

        if ($this->ticket_id !== null) {
            return 'Esta factura es el cargo del ticket #' . $this->ticket_id
                . ' y no puede eliminarse: borrarla dejaría el ticket sin el respaldo del cobro. '
                . 'Anúlala en su lugar — conserva el número, el importe y el vínculo con el ticket.';
        }

        if (filled($this->number)) {
            return 'La factura ' . $this->number . ' ya tiene número asignado y no puede eliminarse. '
                . 'Anúlala en su lugar: conserva el número, los importes, el titular y las fechas.';
        }

        return 'Esta factura está en estado «' . $this->status . '» y no puede eliminarse. '
            . 'Anúlala en su lugar.';
    }
}
