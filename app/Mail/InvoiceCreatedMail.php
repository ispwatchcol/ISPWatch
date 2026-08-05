<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $customerName;
    public $invoiceNumber;
    public $amount;
    public $dueDate;
    public $issueDate;
    public $companyName;
    public $periodLabel;

    /** Facturas con saldo del cliente, ésta incluida. */
    public $pendingCount;

    /** Deuda total del cliente, esta factura incluida. */
    public $pendingTotal;

    /** Lo que ya debía ANTES de esta factura (0 si estaba al día). */
    public $previousDue;

    public function __construct(array $data)
    {
        $this->customerName  = $data['customer_name'];
        $this->invoiceNumber = $data['invoice_number'];
        $this->amount        = $data['amount'];
        $this->dueDate       = $data['due_date'];
        $this->issueDate     = $data['issue_date'] ?? null;
        $this->companyName   = $data['company_name'] ?? 'ISPWatch';
        $this->periodLabel   = $data['period_label'] ?? null;
        $this->pendingCount  = (int) ($data['pending_count'] ?? 1);
        $this->pendingTotal  = (float) ($data['pending_total'] ?? $data['amount']);
        $this->previousDue   = (float) ($data['previous_due'] ?? 0);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🧾 Nueva factura #{$this->invoiceNumber} disponible",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice_created',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
