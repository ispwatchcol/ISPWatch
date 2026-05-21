<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class VerificationController extends Controller
{
    public function verify(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return redirect(config('app.frontend_url', config('app.url')) . '/?error=invalid_link&message=' . urlencode('El enlace de verificación es inválido o ha expirado.'));
        }

        // Validate token to ensure only the most recently sent email works
        $token = $request->query('token');
        if (!$token || !hash_equals((string) $user->email_verification_token, (string) $token)) {
            return redirect(config('app.frontend_url', config('app.url')) . '/?error=invalid_link&message=' . urlencode('Este enlace ya no es válido. Solicita un nuevo correo de verificación.'));
        }

        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url', config('app.url')) . '/?verified=already&message=' . urlencode('El correo ya ha sido verificado anteriormente.'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Consume the token so it can never be reused
        $user->updateQuietly(['email_verification_token' => null]);

        return redirect(config('app.frontend_url', config('app.url')) . '/?verified=success&email_tenant=' . urlencode($user->email_tenant) . '&company=' . urlencode($user->tenant->name ?? 'ISPWatch'));
    }

    public function resend(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $rateLimitKey = 'resend-verification:' . $request->email;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'success' => false,
                'message' => "Demasiadas solicitudes. Intenta de nuevo en {$seconds} segundos.",
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Consume attempt even for unknown emails to prevent enumeration
            RateLimiter::hit($rateLimitKey, 300);
            return response()->json([
                'success' => false,
                'message' => 'No se encontró ninguna cuenta con este correo.',
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Este correo ya está verificado. Inicia sesión con tus credenciales.',
                'already_verified' => true,
            ], 400);
        }

        RateLimiter::hit($rateLimitKey, 300);
        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Te hemos enviado un nuevo enlace de verificación. El enlace anterior ya no es válido.',
        ]);
    }
}
