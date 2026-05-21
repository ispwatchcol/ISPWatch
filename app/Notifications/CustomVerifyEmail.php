<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailBase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class CustomVerifyEmail extends VerifyEmailBase
{
    /**
     * Get the mail representation of the notification.
     *
     * Includes anti-spam improvements:
     * - text/plain alternative for better spam scoring
     * - Proper subject and from headers
     * - Overrides verificationUrl to point to the API route
     */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        // Plain text version for spam filter compatibility
        $plainText = "¡Bienvenido a ISPWatch!\n\n"
            . "Gracias por registrarte. Para verificar tu correo electrónico, visita el siguiente enlace:\n\n"
            . $verificationUrl . "\n\n"
            . "Este enlace expirará en 60 minutos.\n\n"
            . "Si no creaste una cuenta en ISPWatch, puedes ignorar este correo.\n\n"
            . "Saludos,\nEl equipo de ISPWatch";

        return (new MailMessage)
            ->subject('Verifica tu correo electrónico - ISPWatch')
            ->view(
                'emails.verify-email',
                ['url' => $verificationUrl],
                // text/plain alternative improves deliverability
            )
            ->text('emails.verify-email-plain', ['url' => $verificationUrl]);
    }

    /**
     * Override the verification URL to use the API route.
     * The parent class generates a URL for the web route (verification.verify),
     * which may point to a non-existent frontend page. We override it to use
     * the API route that handles verification and redirects to the SPA.
     */
    protected function verificationUrl($notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable);
        }

        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id'    => $notifiable->getKey(),
                'hash'  => sha1($notifiable->getEmailForVerification()),
                'token' => $notifiable->email_verification_token,
            ]
        );
    }
}
