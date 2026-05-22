<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Model
{
    use HasFactory, BelongsToTenant;

    const TYPE_MONTHLY = 'monthly';
    const TYPE_SERVICE_CHARGE = 'service_charge';
    const TYPE_ADDITIONAL = 'additional';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'service_id',
        'invoice_type',
        'ticket_id',
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
        'last_reminder_sent' => 'datetime',
    ];

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

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
