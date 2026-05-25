<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Máximo de intentos de login permitidos por minuto
     */
    private const MAX_ATTEMPTS = 5;
    private const DECAY_MINUTES = 1;

    public function login(Request $request)
    {
        try {
            // ===== 1. RATE LIMITING =====
            $rateLimitKey = $this->getRateLimitKey($request);

            if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);

                Log::warning('Rate limit exceeded for login', [
                    'ip' => $request->ip(),
                    'email_tenant' => $request->email_tenant ?? 'unknown',
                    'retry_after' => $seconds,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Demasiados intentos. Espera {$seconds} segundos.",
                    'retry_after' => $seconds,
                ], 429);
            }

            // ===== 2. VALIDACIÓN =====
            $request->validate([
                'email_tenant' => 'required|string|max:100',
                'password' => 'required|string|max:100',
            ]);

            // ===== 3. SANITIZACIÓN =====
            $emailTenant = $this->sanitizeInput($request->email_tenant);

            // Detectar patrones sospechosos
            if ($this->detectSuspiciousInput($emailTenant)) {
                Log::alert('Suspicious login attempt detected', [
                    'ip' => $request->ip(),
                    'raw_input' => $request->email_tenant,
                    'sanitized_input' => $emailTenant,
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Entrada no válida detectada.',
                ], 400);
            }

            // ===== 4. BUSCAR USUARIO =====
            $user = User::with('role')->where('email_tenant', $emailTenant)->first();

            if (!$user) {
                $this->handleFailedAttempt($request, $rateLimitKey, $emailTenant);
                return response()->json(['success' => false, 'message' => 'Credenciales incorrectas.'], 401);
            }

            // CHECK EMAIL VERIFICATION
            if (!$user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Por favor verifica tu correo electrónico antes de iniciar sesión. Revisa tu bandeja de entrada o usa la opción de reenviar correo.'
                ], 403);
            }

            // ===== 5. VERIFICAR CONTRASEÑA =====
            if (!Hash::check($request->password, $user->password)) {
                $this->handleFailedAttempt($request, $rateLimitKey, $emailTenant);
                return response()->json(['success' => false, 'message' => 'Credenciales incorrectas.'], 401);
            }

            // ===== 6. LOGIN EXITOSO =====
            RateLimiter::clear($rateLimitKey);

            $user->update(['last_access' => now()]);

            // Iniciar sesión para el guard 'web' (Persistencia de sesión)
            // Wrapped in try-catch to handle session issues in production
            try {
                auth()->login($user, true);
                if ($request->hasSession()) {
                    $request->session()->regenerate();
                }
            } catch (\Exception $e) {
                // Log session error but continue - frontend uses localStorage anyway
                Log::warning('Session handling failed during login', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('Successful login', [
                'user_id' => $user->id,
                'email_tenant' => $user->email_tenant,
                'ip' => $request->ip(),
            ]);

            $user->load('role');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'user_name' => $user->user_name,
                    'user_lastname' => $user->user_lastname,
                    'email_tenant' => $user->email_tenant,
                    'role_id' => $user->role_id,
                    'tenant_id' => $user->tenant_id,
                    'role_name' => $user->role?->name ?? 'Sin rol',
                    'role_code' => $user->role?->code ?? null,
                    'permissions' => $user->role?->permissions ?? [],
                    'has_staff_profile' => $user->staffProfile !== null,
                    'is_superadmin' => $user->is_superadmin ?? false,
                ]
            ]);

        } catch (\Exception $e) {
            // Log the full error for debugging (internamente, nunca al usuario)
            Log::error('Login error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Nunca retornar información de debug al usuario
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor.',
            ], 500);
        }
    }

    /**
     * Genera una clave única para rate limiting basada en IP + email
     */
    private function getRateLimitKey(Request $request): string
    {
        return 'login_attempt:' . $request->ip() . ':' . Str::lower($request->email_tenant ?? '');
    }

    /**
     * Maneja un intento de login fallido
     */
    private function handleFailedAttempt(Request $request, string $rateLimitKey, string $emailTenant): void
    {
        RateLimiter::hit($rateLimitKey, self::DECAY_MINUTES * 60);

        Log::warning('Failed login attempt', [
            'ip' => $request->ip(),
            'email_tenant' => $emailTenant,
            'attempts_remaining' => self::MAX_ATTEMPTS - RateLimiter::attempts($rateLimitKey),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Sanitiza la entrada eliminando caracteres peligrosos
     */
    private function sanitizeInput(string $input): string
    {
        // Eliminar tags HTML
        $sanitized = strip_tags($input);

        // Eliminar caracteres de control
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $sanitized);

        // Trim espacios
        $sanitized = trim($sanitized);

        return $sanitized;
    }

    /**
     * Detecta patrones sospechosos de inyección
     */
    private function detectSuspiciousInput(string $input): bool
    {
        $suspiciousPatterns = [
            '/[\'"]/',                    // Comillas (SQL injection)
            '/--/',                       // SQL comment
            '/;/',                        // Statement terminator
            '/\/\*/',                     // SQL block comment
            '/\bunion\b/i',               // SQL UNION
            '/\bselect\b/i',              // SQL SELECT
            '/\bdrop\b/i',                // SQL DROP
            '/\binsert\b/i',              // SQL INSERT
            '/\bdelete\b/i',              // SQL DELETE
            '/\bupdate\b.*\bset\b/i',     // SQL UPDATE SET
            '/<script/i',                 // XSS script
            '/javascript:/i',             // XSS protocol
            '/on\w+\s*=/i',               // XSS event handlers
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna el usuario autenticado con su rol y permisos actuales.
     * Usado por el frontend para refrescar permisos sin cerrar sesión.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return response()->json([
            'success' => true,
            'data' => [
                'id'               => $user->id,
                'user_name'        => $user->user_name,
                'user_lastname'    => $user->user_lastname,
                'email_tenant'     => $user->email_tenant,
                'role_id'          => $user->role_id,
                'tenant_id'        => $user->tenant_id,
                'role_name'        => $user->role?->name ?? 'Sin rol',
                'role_code'        => $user->role?->code ?? null,
                'permissions'      => $user->role?->permissions ?? [],
                'has_staff_profile' => $user->staffProfile !== null,
                'is_superadmin'    => $user->is_superadmin ?? false,
            ],
        ]);
    }
}
