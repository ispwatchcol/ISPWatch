<?php
namespace App\Imports;

use App\Models\User;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\Plan;
use App\Models\UserService;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class CustomersImport implements ToModel, WithHeadingRow, WithValidation
{
    private $rows = 0;
    protected $tenantId;

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function model(array $row)
    {
        $this->rows++;
        $tenantId = $this->tenantId;

        // Lookup router by IP
        $router = Router::where('ip', $row['ip_router'])
            ->where('tenant_id', $tenantId)
            ->first();

        // Lookup service plan by name
        $plan = Plan::where('name', $row['nombre_plan'])
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$router) {
            throw new \Exception("Router with IP {$row['ip_router']} not found");
        }

        if (!$plan) {
            throw new \Exception("Service plan '{$row['nombre_plan']}' not found");
        }

        // Create User
        $user = User::create([
            'name' => trim($row['nombre'] . ' ' . $row['apellido']),
            'user_name' => $row['nombre'],
            'user_lastname' => $row['apellido'],
            'email' => $row['email'],
            'password' => bcrypt('default123'),
            'tel' => $row['telefono'] ?? null,
            'role_id' => 3, // Customer role
            'tenant_id' => $tenantId,
            'status' => true,
            'email_tenant' => $row['email'], // redundant but filling what might be needed
        ]);

        // Create CustomerProfile
        CustomerProfile::create([
            'user_id' => $user->id,
            'name' => $row['nombre'],
            'last_name' => $row['apellido'],
            'address' => $row['direccion'] ?? null,
            'city' => $row['ciudad'] ?? null,
            'ip_user' => $row['ip_usuario'] ?? null,
            'router_id' => $router->id,
            'service_id' => $plan->id,
            'status' => true,
        ]);

        // Create UserService (active plan relationship)
        UserService::create([
            'user_id' => $user->id,
            'service_plan_id' => $plan->id,
            'status' => 'active',
            'start_date' => now(),
        ]);

        return $user;
    }

    public function getRowCount(): int
    {
        return $this->rows;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'nombre' => 'required|string',
            'apellido' => 'required|string',
            'ip_router' => 'required',
            'nombre_plan' => 'required',
        ];
    }
}
