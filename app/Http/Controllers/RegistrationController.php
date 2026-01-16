<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Traits\FixesSequences;

class RegistrationController extends Controller
{
    use FixesSequences;

    /**
     * Admin email to receive notifications
     */
    private const ADMIN_NOTIFICATION_EMAIL = 'axelcano1711@gmail.com';

    /**
     * Register a new tenant with admin user
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
        ]);

        DB::beginTransaction();

        try {
            // 1. Create Tenant with trial status (30 customers max)
            $tenant = $this->createWithSequenceFix(Tenant::class, [
                'name' => $data['company_name'],
                'domain' => $this->generateDomain($data['company_name']),
                'status' => 'trial',
                'max_customers' => 30,
                'email_tenant' => $data['email'],
                'tel_tenant' => $data['phone'] ?? null,
            ]);

            // 2. Get Administrator role (role_id = 1)
            $adminRole = Role::where('name', 'Administrador')->first();
            $roleId = $adminRole ? $adminRole->id : 1;

            // 3. Create Admin User for this tenant
            $nameParts = explode(' ', $data['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            // Generate email_tenant as firstName@companySlug (e.g., axel@colombianet)
            $companySlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['company_name']));
            $emailTenant = strtolower($firstName) . '@' . $companySlug;

            $user = $this->createWithSequenceFix(User::class, [
                'name' => $data['name'], // Required NOT NULL field
                'user_name' => $firstName,
                'user_lastname' => $lastName,
                'email' => $data['email'],
                'email_tenant' => $emailTenant, // e.g., axel@colombianet
                'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                'tel' => $data['phone'] ?? null,
                'role_id' => $roleId,
                'tenant_id' => $tenant->id,
                'status' => true,
            ]);

            DB::commit();

            // 4. Send notification email to admin
            $this->sendNotificationEmail($tenant, $user, $data);

            return response()->json([
                'success' => true,
                'message' => '¡Cuenta creada exitosamente! Tu plan trial incluye hasta 30 clientes.',
                'data' => [
                    'tenant_id' => $tenant->id,
                    'plan' => 'trial',
                    'max_customers' => 30,
                    'email_tenant' => $emailTenant, // Login username
                    'company_name' => $data['company_name'],
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la cuenta.',
                'error' => $e->getMessage()
            ], 500);
        }
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
            $subject = '🆕 Nueva cuenta registrada en ISPWATCH: ' . $tenant->name;

            $message = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px;'>
                        🆕 Nueva Cuenta Registrada
                    </h2>
                    
                    <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3 style='margin-top: 0; color: #1f2937;'>Información del Tenant</h3>
                        <p><strong>Empresa:</strong> {$tenant->name}</p>
                        <p><strong>ID Tenant:</strong> {$tenant->id}</p>
                        <p><strong>Plan:</strong> Trial (30 clientes)</p>
                        <p><strong>Fecha:</strong> " . now()->format('d/m/Y H:i') . "</p>
                    </div>
                    
                    <div style='background: #ecfdf5; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <h3 style='margin-top: 0; color: #065f46;'>Información del Usuario Admin</h3>
                        <p><strong>Nombre:</strong> {$data['name']}</p>
                        <p><strong>Email:</strong> {$data['email']}</p>
                        <p><strong>Teléfono:</strong> " . ($data['phone'] ?? 'No proporcionado') . "</p>
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

            // Send email using Laravel Mail
            Mail::html($message, function ($mail) use ($to, $subject) {
                $mail->to($to)
                    ->subject($subject);
            });

        } catch (\Exception $e) {
            // Log error but don't fail registration
            \Log::error('Failed to send registration notification email: ' . $e->getMessage());
        }
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
