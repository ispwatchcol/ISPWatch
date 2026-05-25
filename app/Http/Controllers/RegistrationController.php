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
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6|max:128',
            'phone' => 'nullable|string|max:20',
        ]);

        // Check if email already exists
        $existingUser = User::where('email', $data['email'])->first();
        if ($existingUser) {
            if (!$existingUser->hasVerifiedEmail()) {
                // User exists but email is not verified - resend verification
                try {
                    $existingUser->sendEmailVerificationNotification();
                    return response()->json([
                        'success' => false,
                        'message' => 'Este correo ya está registrado pero no está verificado. Te hemos enviado un nuevo enlace de verificación a tu correo.',
                        'requires_verification' => true,
                    ], 400);
                } catch (\Exception $e) {
                    Log::error('Failed to resend verification email', [
                        'user_id' => $existingUser->id,
                        'error' => $e->getMessage(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Este correo ya está registrado pero no está verificado. Por favor, solicita un nuevo enlace de verificación.',
                        'requires_verification' => true,
                    ], 400);
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya tienes una cuenta registrada con este correo. Ingresa con tus credenciales.',
                    'already_registered' => true,
                ], 409);
            }
        }

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

            // 2. Create default roles for this new tenant
            $defaultRoles = [
                'Administrador' => ['code' => 'admin',      'permissions' => \App\Constants\Permissions::getPermissionsByRole('admin')],
                'Staff'         => ['code' => 'staff',      'permissions' => \App\Constants\Permissions::getPermissionsByRole('staff')],
                'Cliente'       => ['code' => 'client',     'permissions' => \App\Constants\Permissions::getPermissionsByRole('client')],
                'Contabilidad'  => ['code' => 'accounting', 'permissions' => \App\Constants\Permissions::getPermissionsByRole('accounting')],
                'Técnico'       => ['code' => 'technician', 'permissions' => \App\Constants\Permissions::getPermissionsByRole('technician')],
            ];

            $adminRoleId = null;
            foreach ($defaultRoles as $roleName => $config) {
                $role = Role::create([
                    'name'       => $roleName,
                    'code'       => $config['code'],
                    'permissions' => $config['permissions'],
                    'tenant_id'  => $tenant->id,
                ]);
                if ($config['code'] === 'admin') {
                    $adminRoleId = $role->id;
                }
            }
            $roleId = $adminRoleId ?? 1;

            // 3. Create Admin User for this tenant
            $nameParts = explode(' ', $sanitizedData['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            // Generate email_tenant as nombre.apellido@empresa-separada-por-guiones
            // Example: "Juan Pérez" + "Colombia Net de Occidente" → "juan.perez@colombia-net-de-occidente"
            // Use iconv to transliterate accented characters (é→e, ñ→n, ü→u, etc.)
            $translitFirst = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $firstName) ?: $firstName;
            $firstNameSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $translitFirst));

            $translitLast = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lastName) ?: $lastName;
            $lastNameSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $translitLast));

            // Split company name by spaces and join with hyphens
            $translitCompany = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $sanitizedData['company_name']) ?: $sanitizedData['company_name'];
            $companySlug = strtolower(trim($translitCompany));
            $companySlug = preg_replace('/[^a-z0-9\s]+/', '', $companySlug);  // Keep only letters, numbers and spaces
            $companySlug = preg_replace('/\s+/', '-', $companySlug);            // Spaces → hyphens
            $companySlug = trim($companySlug, '-');

            $emailTenant = $firstNameSlug . ($lastNameSlug ? '.' . $lastNameSlug : '') . '@' . $companySlug;

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
            ]);

            DB::commit();

            // Clear rate limiter on success
            RateLimiter::clear($rateLimitKey);

            // Send email verification link
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Exception $e) {
                Log::error('Failed to send verification email', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Log successful registration
            Log::info('New user registered', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'email' => $sanitizedData['email'],
                'ip' => $request->ip(),
            ]);



            return response()->json([
                'success' => true,
                'message' => '¡Cuenta creada exitosamente! Tu plan trial incluye hasta 30 clientes. Te enviamos un enlace de verificación a tu correo electrónico.',
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

        // Ensure uniqueness with a numeric counter instead of a timestamp
        $domain = $slug;
        $counter = 2;
        while (Tenant::where('domain', $domain)->exists()) {
            $domain = $slug . '-' . $counter;
            $counter++;
        }

        return $domain;
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
