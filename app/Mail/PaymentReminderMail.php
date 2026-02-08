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
     * Create a new message instance.
     */
    public function __construct(array $data)
    {
        $this->customerName = $data['customer_name'];
        $this->invoiceNumber = $data['invoice_number'];
        $this->amount = $data['amount'];
        $this->dueDate = $data['due_date'];
        $this->companyName = $data['company_name'] ?? 'ISPWatch';
        $this->isOverdue = $data['is_overdue'] ?? false;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->isOverdue
            ? "⚠️ Factura Vencida #{$this->invoiceNumber} - Pago Urgente"
            : "📣 Recordatorio de Pago - Factura #{$this->invoiceNumber}";

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
