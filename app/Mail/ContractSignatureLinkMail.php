<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitación a firmar el contrato desde el link personal.
 *
 * El correo lleva el token en la URL, así que es el único punto del sistema
 * donde el token viaja en claro por un canal que no controlamos. De ahí las
 * dos defensas del otro lado: expira (72h por defecto) y, si el cliente tiene
 * cédula registrada, hay que confirmar sus últimos 4 dígitos para abrirlo.
 */
class ContractSignatureLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $companyName,
        public string $signingUrl,
        public string $expiresAt,
        public bool $isReminder = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isReminder
                ? "Recordatorio: tu contrato con {$this->companyName} sigue pendiente de firma"
                : "Firma tu contrato de servicio con {$this->companyName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contract_signature_link',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
