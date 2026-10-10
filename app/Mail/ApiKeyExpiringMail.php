<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que una llave de la API pública vence pronto (P-KEYS-1 / KAN-43).
 *
 * El vencimiento obligatorio fuerza la rotación de la forma más brusca: el día
 * que vence, la integración se cae. Este correo da margen para emitir la llave
 * nueva antes. No lleva el token, que el servidor ni siquiera conoce (guarda un
 * hash), ni la allowlist: sólo lo necesario para identificar la llave.
 */
class ApiKeyExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $integrationName,
        public string $keyName,
        public int $tenantId,
        public string $expiresAt,
        public int $daysLeft,
        public ?string $lastUsedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[ISPWatch] La llave de API «{$this->keyName}» vence en {$this->daysLeft} día(s)",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.api_key_expiring_text');
    }

    public function attachments(): array
    {
        return [];
    }
}
