<?php

use Illuminate\Support\Facades\Mail;

// Test email sending with Brevo SMTP
Mail::raw('Este es un email de prueba desde ISPWatch usando Brevo (Sendinblue)', function ($message) {
    $message->to('axelcano1711@gmail.com')
        ->subject('Test ISPWatch - Brevo SMTP');
});

echo "Email de prueba enviado exitosamente!\n";
echo "Revisa tu bandeja de entrada: axelcano1711@gmail.com\n";
