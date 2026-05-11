<?php
namespace App\Imports\Sheets;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Row;

class CustomersSheetImport implements OnEachRow, WithHeadingRow, WithTitle
{
    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function title(): string
    {
        return 'Clientes';
    }

    public function onRow(Row $row)
    {
        $rowNumber = $row->getIndex();
        $data = $row->toArray();

        if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
            return;
        }

        $missing = [];
        foreach (['email', 'nombre', 'apellido', 'ip_router', 'nombre_plan'] as $field) {
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
            return;
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = [
                'sheet' => 'Clientes',
                'row' => $rowNumber,
                'field' => 'email',
                'error' => 'Email inválido',
            ];
            return;
        }

        if (User::where('email', $data['email'])->exists()) {
            $this->errors[] = [
                'sheet' => 'Clientes',
                'row' => $rowNumber,
                'field' => 'email',
                'error' => "El email {$data['email']} ya está registrado",
            ];
            return;
        }

        $router = Router::where('ip', $data['ip_router'])
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$router) {
            $this->errors[] = [
                'sheet' => 'Clientes',
                'row' => $rowNumber,
                'field' => 'ip_router',
                'error' => "Router con IP {$data['ip_router']} no encontrado. Asegúrese que esté en la hoja Routers o ya exista en el sistema.",
            ];
            return;
        }

        $plan = Plan::where('name', $data['nombre_plan'])
            ->where('tenant_id', $this->tenantId)
            ->first();
        if (!$plan) {
            $this->errors[] = [
                'sheet' => 'Clientes',
                'row' => $rowNumber,
                'field' => 'nombre_plan',
                'error' => "Plan '{$data['nombre_plan']}' no encontrado. Asegúrese que esté en la hoja Planes o ya exista en el sistema.",
            ];
            return;
        }

        $sectorialId = null;
        if (!empty($data['nombre_sectorial'])) {
            $sectorial = Sectorial::where('name', $data['nombre_sectorial'])->first();
            if (!$sectorial) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'nombre_sectorial',
                    'error' => "Sectorial '{$data['nombre_sectorial']}' no encontrada.",
                ];
                return;
            }
            $sectorialId = $sectorial->id;
        }

        $tenant = Tenant::find($this->tenantId);
        $firstName = strtolower(preg_replace('/\s+/', '', $data['nombre']));
        $lastName = strtolower(preg_replace('/\s+/', '', $data['apellido']));
        $domain = $tenant ? strtolower($tenant->domain ?? 'local') : 'local';
        $emailTenant = "{$firstName}.{$lastName}@{$domain}";

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => trim($data['nombre'] . ' ' . $data['apellido']),
                'user_name' => $data['nombre'],
                'user_lastname' => $data['apellido'],
                'email' => $data['email'],
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
                'router_id' => $router->id,
                'service_id' => $plan->id,
                'sectorial_id' => $sectorialId,
                'status' => true,
            ]);

            UserService::create([
                'user_id' => $user->id,
                'service_plan_id' => $plan->id,
                'status' => 'active',
                'start_date' => now(),
            ]);

            DB::commit();
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
