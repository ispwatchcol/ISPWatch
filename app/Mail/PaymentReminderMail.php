<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $customerName;
    public $invoiceNumber;
    public $amount;
    public $dueDate;
    public $companyName;
    public $isOverdue;

    /**
     * Facturas pendientes incluidas en este aviso. Un cliente que debe varias
     * recibe UN solo correo con el detalle de todas, no uno por factura.
     *
     * @var array<int,array{number:string,amount:float,due_date:mixed,is_overdue:bool}>
     */
    public $invoices;

    /** Cuántas facturas van en el aviso (1 = mensaje clásico de una factura). */
    public $invoiceCount;

    /**
     * Create a new message instance.
     */
    public function __construct(array $data)
    {
        $this->customerName = $data['customer_name'];
        $this->invoiceNumber = $data['invoice_number'];
        // Con varias facturas, $amount es el TOTAL adeudado.
        $this->amount = $data['amount'];
        // Con varias facturas, $dueDate es el vencimiento más antiguo.
        $this->dueDate = $data['due_date'];
        $this->companyName = $data['company_name'] ?? 'ISPWatch';
        $this->isOverdue = $data['is_overdue'] ?? false;
        $this->invoices = $data['invoices'] ?? [];
        $this->invoiceCount = (int) ($data['invoice_count'] ?? max(1, count($this->invoices)));
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        if ($this->invoiceCount > 1) {
            $subject = $this->isOverdue
                ? "⚠️ Tienes {$this->invoiceCount} facturas pendientes - Pago Urgente"
                : "📣 Recordatorio de Pago - {$this->invoiceCount} facturas pendientes";
        } else {
            $subject = $this->isOverdue
                ? "⚠️ Factura Vencida #{$this->invoiceNumber} - Pago Urgente"
                : "📣 Recordatorio de Pago - Factura #{$this->invoiceNumber}";
        }

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.payment_reminder',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
