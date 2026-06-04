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
    private const CHUNK = 200;

    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];
    protected $routers = [];
    protected $routersPppoe = [];
    protected $plans = [];
    protected $courtesyPlanIds = [];
    protected $sectorials = [];
    protected $existingEmails = [];
    protected $existingIps = [];
    protected $tenantDomain = 'local';
    protected int $maxCustomers = 0;
    protected int $currentCustomerCount = 0;
    protected string $tenantStatus = 'trial';
    protected bool $limitReached = false;

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

        // Courtesy plans -> their customers get user_services.status = 'gratis'
        // so the monthly billing job never auto-invoices them. Flipped to use
        // O(1) isset() lookups by plan id in flush().
        $this->courtesyPlanIds = Plan::where('tenant_id', $this->tenantId)
            ->where('is_courtesy', true)
            ->pluck('id')
            ->flip()
            ->toArray();

        $this->sectorials = Sectorial::pluck('id', 'name')->toArray();

        $this->existingEmails = User::where('tenant_id', $this->tenantId)
            ->pluck('email')
            ->flip()
            ->toArray();

        // IP única POR ROUTER/CORE (no por tenant): la misma IP puede repetirse en
        // routers distintos, pero nunca dentro del mismo router. Se indexa por
        // "router_id|ip" para detectar choques solo dentro del mismo CORE.
        $this->existingIps = CustomerProfile::whereHas('user', fn($q) => $q->where('tenant_id', $this->tenantId))
            ->whereNotNull('ip_user')
            ->where('ip_user', '!=', '')
            ->get(['router_id', 'ip_user'])
            ->mapWithKeys(fn($c) => [$c->router_id . '|' . $c->ip_user => true])
            ->toArray();

        $tenant = Tenant::find($this->tenantId);
        $this->tenantDomain = User::sanitizeEmail($tenant->domain ?? 'local') ?: 'local';

        // 0 o null = ilimitado (solo cuando el plan está marcado como tal)
        $this->maxCustomers = (int) ($tenant->max_customers ?? 0);
        $this->tenantStatus = (string) ($tenant->status ?? 'trial');
        $this->currentCustomerCount = CustomerProfile::whereHas(
            'user',
            fn($q) => $q->where('tenant_id', $this->tenantId)
        )->count();
    }

    public function title(): string
    {
        return 'Clientes';
    }

    public function collection(Collection $rows)
    {
        // Fase 1: validación fila por fila (errores granulares como antes).
        // Las filas válidas se acumulan; nada se inserta todavía.
        $pending = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            // Límite de clientes del dominio/tenant. maxCustomers <= 0 = ilimitado.
            if ($this->maxCustomers > 0
                && ($this->currentCustomerCount + count($pending)) >= $this->maxCustomers) {
                if (!$this->limitReached) {
                    $this->limitReached = true;
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'limite',
                        'error' => "Límite de {$this->maxCustomers} clientes alcanzado para tu plan {$this->tenantStatus}. "
                            . "Las filas desde la {$rowNumber} en adelante no fueron importadas. "
                            . "Contacta con soporte para ampliar tu plan.",
                    ];
                }
                break;
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

            // IP única por router/CORE: choca solo si ya existe esa IP en el MISMO
            // router. La misma IP en otro router es válida.
            $ipKey = $routerId . '|' . $data['ip_usuario'];
            if (isset($this->existingIps[$ipKey])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'ip_usuario',
                    'error' => "La IP {$data['ip_usuario']} ya está asignada a otro cliente en el mismo router/CORE ({$data['ip_router']}). Puede repetirse solo en un router distinto.",
                ];
                continue;
            }

            // email_tenant (login) normalizado a ASCII: nunca lleva ñ ni tildes,
            // aunque el nombre/apellido sí los conserven.
            $firstName = User::sanitizeEmail($data['nombre']);
            $lastName = User::sanitizeEmail($data['apellido']);
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

            $installationDate = null;
            if (isset($data['fecha_instalacion']) && $data['fecha_instalacion'] !== '') {
                $installationDate = $this->parseExcelDate($data['fecha_instalacion']);
                if ($installationDate === null) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'fecha_instalacion',
                        'error' => 'Fecha de instalación inválida. Usa el formato AAAA-MM-DD (ej. 2026-05-28).',
                    ];
                    continue;
                }
            }

            $estrato = null;
            if (isset($data['estrato']) && $data['estrato'] !== '') {
                $estrato = (int) $data['estrato'];
                if ($estrato < 1 || $estrato > 6) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'estrato',
                        'error' => 'Estrato inválido. Debe ser un número entre 1 y 6.',
                    ];
                    continue;
                }
            }

            // Reservar email/IP para que un duplicado posterior en el MISMO archivo
            // se detecte aunque todavía no se haya insertado nada en la BD.
            // La IP se reserva por router/CORE (misma clave que la validación).
            $this->existingEmails[$customerEmail] = true;
            $this->existingIps[$ipKey] = true;

            $pending[] = [
                'name' => trim($data['nombre'] . ' ' . $data['apellido']),
                'user_name' => $data['nombre'],
                'user_lastname' => $data['apellido'],
                'email' => $customerEmail,
                'email_tenant' => $emailTenant,
                'tel' => $data['telefono'] ?? null,
                'cedula' => $data['cedula'] ?? null,
                'router_id' => $routerId,
                'plan_id' => $planId,
                'sectorial_id' => $sectorialId,
                'ip_user' => $data['ip_usuario'] ?? null,
                'address' => $data['direccion'] ?? null,
                'city' => $data['ciudad'] ?? null,
                'pppoe_username' => $data['usuario_pppoe'] ?? null,
                'pppoe_password' => $data['password_pppoe'] ?? null,
                'installation_date' => $installationDate,
                'estrato' => $estrato,
            ];
        }

        if (empty($pending)) {
            return;
        }

        $this->flush($pending);
    }

    /**
     * Fase 2: inserción en bloque. Reemplaza la transacción+3 inserts por fila
     * por inserts masivos en chunks, y hashea la contraseña por defecto UNA sola
     * vez (bcrypt es el principal cuello de botella en cargas grandes).
     */
    protected function flush(array $pending): void
    {
        $now = now();
        // Hash único reutilizado: la contraseña por defecto es constante, así que
        // no tiene sentido pagar el costo de bcrypt una vez por cada fila.
        $defaultPassword = Hash::make('default123');

        $userRows = [];
        foreach ($pending as $p) {
            $userRows[] = [
                // El hook booted()->saving de User (deriva `name` de user_name +
                // user_lastname) NO se dispara con insert() masivo, por eso `name`
                // se calcula aquí explícitamente.
                'name' => $p['name'],
                'user_name' => $p['user_name'],
                'user_lastname' => $p['user_lastname'],
                'email' => $p['email'],
                'email_tenant' => $p['email_tenant'],
                'tel' => $p['tel'],
                'password' => $defaultPassword,
                'role_id' => 3,
                'tenant_id' => $this->tenantId,
                'status' => true,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            DB::transaction(function () use ($pending, $userRows, $now) {
                foreach (array_chunk($userRows, self::CHUNK) as $chunk) {
                    User::insert($chunk);
                }

                // Resolver los IDs recién creados por email. El email es único en
                // `users` y los emails ya existentes fueron rechazados en validación,
                // así que este mapeo corresponde solo a las filas recién insertadas.
                $emails = array_column($pending, 'email');
                $idByEmail = [];
                foreach (array_chunk($emails, 500) as $emailChunk) {
                    $idByEmail += User::where('tenant_id', $this->tenantId)
                        ->whereIn('email', $emailChunk)
                        ->pluck('id', 'email')
                        ->toArray();
                }

                $profileRows = [];
                $serviceRows = [];
                foreach ($pending as $p) {
                    $userId = $idByEmail[$p['email']] ?? null;
                    if ($userId === null) {
                        // No debería ocurrir: el insert previo fue exitoso.
                        throw new \RuntimeException("No se pudo resolver el ID para {$p['email']}");
                    }

                    $profileRows[] = [
                        'user_id' => $userId,
                        'name' => $p['user_name'],
                        'last_name' => $p['user_lastname'],
                        'cedula' => $p['cedula'],
                        'address' => $p['address'],
                        'city' => $p['city'],
                        'ip_user' => $p['ip_user'],
                        'router_id' => $p['router_id'],
                        'service_id' => $p['plan_id'],
                        'sectorial_id' => $p['sectorial_id'],
                        'pppoe_username' => $p['pppoe_username'],
                        'pppoe_password' => $p['pppoe_password'],
                        'installation_date' => $p['installation_date'],
                        'estrato' => $p['estrato'],
                        'status' => true,
                    ];

                    $serviceRows[] = [
                        'user_id' => $userId,
                        'service_plan_id' => $p['plan_id'],
                        // Courtesy plan -> 'gratis' (never auto-invoiced).
                        'status' => isset($this->courtesyPlanIds[$p['plan_id']])
                            ? UserService::STATUS_GRATIS
                            : UserService::STATUS_ACTIVE,
                        'start_date' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($profileRows, self::CHUNK) as $chunk) {
                    CustomerProfile::insert($chunk);
                }
                foreach (array_chunk($serviceRows, self::CHUNK) as $chunk) {
                    UserService::insert($chunk);
                }
            });

            $this->imported += count($pending);
        } catch (\Throwable $e) {
            // La inserción es atómica: si falla, no se importó ningún cliente.
            $this->errors[] = [
                'sheet' => 'Clientes',
                'row' => '-',
                'field' => '-',
                'error' => 'No se pudo guardar el lote de clientes: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Convierte una celda de Excel (número serial o texto) a 'Y-m-d'.
     * Devuelve null si el valor no es una fecha válida.
     */
    protected function parseExcelDate($value): ?string
    {
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return \Carbon\Carbon::parse(trim((string) $value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
