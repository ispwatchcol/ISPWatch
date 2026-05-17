<?php
namespace App\Imports\Sheets;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomersSheetImport implements ToCollection, WithHeadingRow, WithTitle
{
    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];
    protected $routers = [];
    protected $routersPppoe = [];
    protected $plans = [];
    protected $sectorials = [];
    protected $existingEmails = [];
    protected $existingIps = [];
    protected $tenantDomain = 'local';

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->loadCaches();
    }

    protected function loadCaches(): void
    {
        $routerData = Router::where('tenant_id', $this->tenantId)
            ->select('id', 'ip', 'pppoe')
            ->get();

        foreach ($routerData as $router) {
            $this->routers[$router->ip] = $router->id;
            $this->routersPppoe[$router->id] = $router->pppoe;
        }

        $this->plans = Plan::where('tenant_id', $this->tenantId)
            ->pluck('id', 'name')
            ->toArray();

        $this->sectorials = Sectorial::pluck('id', 'name')->toArray();

        $this->existingEmails = User::where('tenant_id', $this->tenantId)
            ->pluck('email')
            ->flip()
            ->toArray();

        $this->existingIps = CustomerProfile::whereHas('user', fn($q) => $q->where('tenant_id', $this->tenantId))
            ->pluck('ip_user')
            ->filter()
            ->flip()
            ->toArray();

        $tenant = Tenant::find($this->tenantId);
        $this->tenantDomain = strtolower($tenant->domain ?? 'local');
    }

    public function title(): string
    {
        return 'Clientes';
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $missing = [];
            foreach (['nombre', 'apellido', 'ip_usuario', 'ip_router', 'nombre_plan', 'nombre_sectorial'] as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => implode(', ', $missing),
                    'error' => 'Campos obligatorios faltantes',
                ];
                continue;
            }

            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'email',
                    'error' => 'Email inválido',
                ];
                continue;
            }

            if (!isset($this->routers[$data['ip_router']])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'ip_router',
                    'error' => "Router con IP {$data['ip_router']} no encontrado. Asegúrese que esté en la hoja Routers o ya exista en el sistema.",
                ];
                continue;
            }
            $routerId = $this->routers[$data['ip_router']];

            if ($this->routersPppoe[$routerId]) {
                $pppoeMissing = [];
                if (empty($data['usuario_pppoe'])) $pppoeMissing[] = 'usuario_pppoe';
                if (empty($data['password_pppoe'])) $pppoeMissing[] = 'password_pppoe';
                if (!empty($pppoeMissing)) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => implode(', ', $pppoeMissing),
                        'error' => "El router usa Control PPPOE — credenciales PPPoE obligatorias.",
                    ];
                    continue;
                }
            }

            if (!isset($this->plans[$data['nombre_plan']])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'nombre_plan',
                    'error' => "Plan '{$data['nombre_plan']}' no encontrado. Asegúrese que esté en la hoja Planes o ya exista en el sistema.",
                ];
                continue;
            }
            $planId = $this->plans[$data['nombre_plan']];

            if (!isset($this->sectorials[$data['nombre_sectorial']])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'nombre_sectorial',
                    'error' => "Sectorial '{$data['nombre_sectorial']}' no encontrada.",
                ];
                continue;
            }
            $sectorialId = $this->sectorials[$data['nombre_sectorial']];

            if (isset($this->existingIps[$data['ip_usuario']])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'ip_usuario',
                    'error' => "La IP {$data['ip_usuario']} ya está asignada a otro cliente",
                ];
                continue;
            }

            $firstName = strtolower(preg_replace('/\s+/', '', $data['nombre']));
            $lastName = strtolower(preg_replace('/\s+/', '', $data['apellido']));
            $emailTenant = "{$firstName}.{$lastName}@{$this->tenantDomain}";
            $customerEmail = !empty($data['email']) ? $data['email'] : $emailTenant;

            if (isset($this->existingEmails[$customerEmail])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'email',
                    'error' => "El email {$customerEmail} ya está registrado. Si este cliente tiene un segundo servicio, especifique un email distinto en el Excel.",
                ];
                continue;
            }

            DB::beginTransaction();
            try {
                $user = User::create([
                    'name' => trim($data['nombre'] . ' ' . $data['apellido']),
                    'user_name' => $data['nombre'],
                    'user_lastname' => $data['apellido'],
                    'email' => $customerEmail,
                    'email_tenant' => $emailTenant,
                    'password' => Hash::make('default123'),
                    'tel' => $data['telefono'] ?? null,
                    'role_id' => 3,
                    'tenant_id' => $this->tenantId,
                    'status' => true,
                    'email_verified_at' => now(),
                ]);

                CustomerProfile::create([
                    'user_id' => $user->id,
                    'name' => $data['nombre'],
                    'last_name' => $data['apellido'],
                    'address' => $data['direccion'] ?? null,
                    'city' => $data['ciudad'] ?? null,
                    'ip_user' => $data['ip_usuario'] ?? null,
                    'router_id' => $routerId,
                    'service_id' => $planId,
                    'sectorial_id' => $sectorialId,
                    'pppoe_username' => $data['usuario_pppoe'] ?? null,
                    'pppoe_password' => $data['password_pppoe'] ?? null,
                    'status' => true,
                ]);

                UserService::create([
                    'user_id' => $user->id,
                    'service_plan_id' => $planId,
                    'status' => 'active',
                    'start_date' => now(),
                ]);

                DB::commit();
                $this->existingEmails[$customerEmail] = true;
                $this->existingIps[$data['ip_usuario']] = true;
                $this->imported++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => '-',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }
}
