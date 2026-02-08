<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function verify($id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            // Redirect to frontend with error
            return redirect(config('app.url') . '/?error=invalid_link&message=' . urlencode('Link de verificación inválido o expirado.'));
        }

        if ($user->hasVerifiedEmail()) {
            // Redirect to frontend with info that email was already verified
            return redirect(config('app.url') . '/?verified=already&message=' . urlencode('El correo ya ha sido verificado anteriormente.'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Redirect to frontend login with success message
        return redirect(config('app.url') . '/?verified=success&email_tenant=' . urlencode($user->email_tenant) . '&company=' . urlencode($user->tenant->name ?? 'ISPWatch'));
    }

    /**
     * Resend the email verification notification.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            $request->validate(['email' => 'required|email']);
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No se encontró ninguna cuenta con este correo.'], 404);
            }
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'El correo ya está verificado.'
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Enlace de verificación reenviado. Revisa tu bandeja de entrada.'
        ]);
    }
}
