<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Traits\FixesSequences;
use App\Traits\InputSanitizer;

class RegistrationController extends Controller
{
    use FixesSequences, InputSanitizer;

    /**
     * Admin email to receive notifications
     */
    private const ADMIN_NOTIFICATION_EMAIL = 'axelcano1711@gmail.com';

    /**
     * Rate limiting configuration
     */
    private const MAX_ATTEMPTS = 5;
    private const DECAY_MINUTES = 5;

    /**
     * Register a new tenant with admin user
     */
    public function register(Request $request)
    {
        // ===== 1. RATE LIMITING =====
        $rateLimitKey = $this->getRateLimitKey($request);

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            Log::warning('Registration rate limit exceeded', [
                'ip' => $request->ip(),
                'retry_after' => $seconds,
            ]);

            return response()->json([
                'success' => false,
                'message' => "Demasiados intentos de registro. Espera {$seconds} segundos.",
                'retry_after' => $seconds,
            ], 429);
        }

        // ===== 2. VALIDATION =====
        $data = $request->validate([
            'company_name' => 'required|string|max:255|min:2',
            'name' => 'required|string|max:255|min:2',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|max:128',
            'phone' => 'nullable|string|max:20',
            'verification_code' => 'required|string',
        ]);

        // Verify Code
        $cachedCode = \Illuminate\Support\Facades\Cache::get('verification_code_' . $data['email']);

        if (!$cachedCode || $cachedCode != $data['verification_code']) {
            return response()->json([
                'success' => false,
                'message' => 'Código de verificación inválido o expirado.',
            ], 400);
        }

        // Remove code from cache to prevent reuse
        \Illuminate\Support\Facades\Cache::forget('verification_code_' . $data['email']);

        // ===== 3. SECURITY CHECKS =====
        // Check for SQL injection patterns
        $fieldsToCheck = [$data['company_name'], $data['name'], $data['email']];
        foreach ($fieldsToCheck as $field) {
            if ($this->detectSuspiciousInput($field)) {
                RateLimiter::hit($rateLimitKey, self::DECAY_MINUTES * 60);

                Log::alert('Suspicious registration attempt detected', [
                    'ip' => $request->ip(),
                    'field' => $field,
                    'user_agent' => $request->userAgent(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Entrada no válida detectada.',
                ], 400);
            }
        }

        // ===== 4. SANITIZE INPUTS =====
        $sanitizedData = [
            'company_name' => $this->sanitizeForDatabase($data['company_name']),
            'name' => $this->sanitizeForDatabase($data['name']),
            'email' => $this->sanitizeEmail($data['email']),
            'phone' => $data['phone'] ? $this->sanitizePhone($data['phone']) : null,
            'password' => $data['password'], // Don't sanitize passwords
        ];

        // Increment rate limiter (will be cleared on success)
        RateLimiter::hit($rateLimitKey, self::DECAY_MINUTES * 60);

        DB::beginTransaction();

        try {
            // 1. Create Tenant with trial status (30 customers max)
            $tenant = $this->createWithSequenceFix(Tenant::class, [
                'name' => $sanitizedData['company_name'],
                'domain' => $this->generateDomain($sanitizedData['company_name']),
                'status' => 'trial',
                'max_customers' => 30,
                'email_tenant' => $sanitizedData['email'],
                'tel_tenant' => $sanitizedData['phone'],
            ]);

            // 2. Get Administrator role (role_id = 1)
            $adminRole = Role::where('name', 'Administrador')->first();
            $roleId = $adminRole ? $adminRole->id : 1;

            // 3. Create Admin User for this tenant
            $nameParts = explode(' ', $sanitizedData['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            // Generate email_tenant as firstName@companySlug (e.g., axel@colombianet)
            $companySlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sanitizedData['company_name']));
            $emailTenant = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName)) . '@' . $companySlug;

            $user = $this->createWithSequenceFix(User::class, [
                'name' => $sanitizedData['name'],
                'user_name' => $firstName,
                'user_lastname' => $lastName,
                'email' => $sanitizedData['email'],
                'email_tenant' => $emailTenant,
                'password' => Hash::make($sanitizedData['password']),
                'tel' => $sanitizedData['phone'],
                'role_id' => $roleId,
                'tenant_id' => $tenant->id,
                'status' => true,
                'email_verified_at' => now(), // Email verified by code before creation
            ]);

            DB::commit();

            // Clear rate limiter on success
            RateLimiter::clear($rateLimitKey);

            // Log successful registration
            Log::info('New user registered', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'email' => $sanitizedData['email'],
                'ip' => $request->ip(),
            ]);

            // 5. Send notification email to admin
            $this->sendNotificationEmail($tenant, $user, $sanitizedData);

            return response()->json([
                'success' => true,
                'message' => '¡Cuenta creada exitosamente! Tu plan trial incluye hasta 30 clientes.',
                'data' => [
                    'tenant_id' => $tenant->id,
                    'plan' => 'trial',
                    'max_customers' => 30,
                    'email_tenant' => $emailTenant,
                    'company_name' => $sanitizedData['company_name'],
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'email' => $sanitizedData['email'] ?? 'unknown',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la cuenta. Por favor intenta de nuevo.',
            ], 500);
        }
    }

    /**
     * Generate rate limit key based on IP
     */
    private function getRateLimitKey(Request $request): string
    {
        return 'register_attempt:' . $request->ip();
    }

    /**
     * Generate a URL-friendly domain from company name
     */
    private function generateDomain(string $companyName): string
    {
        $slug = strtolower(trim($companyName));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Add timestamp to ensure uniqueness
        return $slug . '-' . time();
    }

    /**
     * Send notification email to admin about new registration
     */
    private function sendNotificationEmail(Tenant $tenant, $user, array $data): void
    {
        try {
            $to = self::ADMIN_NOTIFICATION_EMAIL;

            // Escape data for HTML output (XSS prevention in email)
            $safeTenantName = htmlspecialchars($tenant->name, ENT_QUOTES, 'UTF-8');
            $safeName = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
            $safeEmail = htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8');
            $safePhone = htmlspecialchars($data['phone'] ?? 'No proporcionado', ENT_QUOTES, 'UTF-8');

            $subject = '🆕 Nueva cuenta registrada en ISPWATCH: ' . $safeTenantName;

            $message = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px;'>
                        🆕 Nueva Cuenta Registrada
                    </h2>
                    
                    <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3 style='margin-top: 0; color: #1f2937;'>Información del Tenant</h3>
                        <p><strong>Empresa:</strong> {$safeTenantName}</p>
                        <p><strong>ID Tenant:</strong> {$tenant->id}</p>
                        <p><strong>Plan:</strong> Trial (30 clientes)</p>
                        <p><strong>Fecha:</strong> " . now()->format('d/m/Y H:i') . "</p>
                    </div>
                    
                    <div style='background: #ecfdf5; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3 style='margin-top: 0; color: #065f46;'>Información del Usuario Admin</h3>
                        <p><strong>Nombre:</strong> {$safeName}</p>
                        <p><strong>Email:</strong> {$safeEmail}</p>
                        <p><strong>Teléfono:</strong> {$safePhone}</p>
                    </div>
                    
                    <div style='background: #fef3c7; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 0; color: #92400e;'>
                            <strong>💡 Tip:</strong> Contacta a este usuario cuando esté cerca de alcanzar 
                            su límite de 30 clientes para ofrecerle un plan superior.
                        </p>
                    </div>
                    
                    <p style='color: #6b7280; font-size: 12px; margin-top: 30px;'>
                        Este es un correo automático de ISPWATCH.
                    </p>
                </div>
            </body>
            </html>
            ";

            Mail::html($message, function ($mail) use ($to, $subject) {
                $mail->to($to)
                    ->subject($subject);
            });

        } catch (\Exception $e) {
            Log::error('Failed to send registration notification email: ' . $e->getMessage());
        }
    }

    /**
     * Send verification code to email
     */
    public function sendVerificationCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Check if user already exists
        if (User::where('email', $request->email)->exists()) {
            return response()->json(['success' => false, 'message' => 'Este correo ya está registrado.'], 400);
        }

        $email = $request->email;
        $code = rand(100000, 999999);

        // Store in cache for 10 minutes
        \Illuminate\Support\Facades\Cache::put('verification_code_' . $email, $code, 600);

        try {
            Mail::to($email)->send(new \App\Mail\VerificationCodeMail($code));
        } catch (\Exception $e) {
            Log::error('Failed to send verification code', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al enviar el código. Intenta nuevamente.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Código de verificación enviado.']);
    }

    /**
     * Get plan limits for reference
     */
    public static function getPlanLimits(): array
    {
        return [
            'trial' => 30,
            'basic' => 100,
            'pro' => 500,
            'enterprise' => 0, // 0 = unlimited
        ];
    }
}
