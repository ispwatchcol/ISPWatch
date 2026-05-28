<?php
namespace App\Imports;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CustomersUpdateImport implements ToCollection, WithHeadingRow
{
    protected int $tenantId;

    public int $updated = 0;
    public array $errors = [];

    protected array $routers = [];
    protected array $plans = [];
    protected array $courtesyPlanIds = [];
    protected array $sectorials = [];
    protected array $emailToUserId = [];
    protected array $existingEmails = [];
    protected array $ipToUserId = [];

    /** cedula => [user_id, ...]. Si una cédula apunta a varios clientes es ambigua. */
    protected array $cedulaToUserIds = [];

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->loadCaches();
    }

    protected function loadCaches(): void
    {
        foreach (Router::where('tenant_id', $this->tenantId)->select('id', 'ip')->get() as $r) {
            $this->routers[$r->ip] = $r->id;
        }

        $this->plans = Plan::where('tenant_id', $this->tenantId)
            ->pluck('id', 'name')
            ->toArray();

        $this->courtesyPlanIds = Plan::where('tenant_id', $this->tenantId)
            ->where('is_courtesy', true)
            ->pluck('id')
            ->flip()
            ->toArray();

        $this->sectorials = Sectorial::pluck('id', 'name')->toArray();

        $users = User::where('tenant_id', $this->tenantId)
            ->select('id', 'email')
            ->get();
        foreach ($users as $u) {
            $this->emailToUserId[$u->email] = $u->id;
            $this->existingEmails[$u->email] = $u->id;
        }

        $profiles = CustomerProfile::whereHas('user', fn($q) => $q->where('tenant_id', $this->tenantId))
            ->select('user_id', 'ip_user', 'cedula')
            ->get();
        foreach ($profiles as $p) {
            if ($p->ip_user) {
                $this->ipToUserId[$p->ip_user] = $p->user_id;
            }
            $cedula = trim((string) ($p->cedula ?? ''));
            if ($cedula !== '') {
                $this->cedulaToUserIds[$cedula][] = $p->user_id;
            }
        }
    }

    /**
     * Resuelve a qué cliente apunta una fila. La llave principal es email_actual;
     * si falta o no existe, se usa la cédula (dato que casi siempre se tiene).
     * Devuelve [userId|null, reason] donde reason ∈ missing|not_found|ambiguous.
     */
    protected function resolveUserId(string $emailActual, string $cedula): array
    {
        if ($emailActual !== '' && isset($this->emailToUserId[$emailActual])) {
            return [$this->emailToUserId[$emailActual], null];
        }

        if ($cedula !== '' && isset($this->cedulaToUserIds[$cedula])) {
            $ids = array_values(array_unique($this->cedulaToUserIds[$cedula]));
            if (count($ids) === 1) {
                return [$ids[0], null];
            }
            return [null, 'ambiguous'];
        }

        if ($emailActual === '' && $cedula === '') {
            return [null, 'missing'];
        }

        return [null, 'not_found'];
    }

    public function collection(Collection $rows)
    {
        // Cargas grandes (200+ filas) contra la BD remota se quedaban sin tiempo
        // y el gateway respondía 504. Antes hacíamos 3-4 SELECT + una transacción
        // por fila; ahora precargamos en bloque los modelos que se van a tocar y
        // escribimos con el mínimo de round-trips.
        @set_time_limit(0);

        $targetIds = [];
        foreach ($rows as $row) {
            $d = is_array($row) ? $row : $row->toArray();
            [$uid] = $this->resolveUserId(
                trim((string) ($d['email_actual'] ?? '')),
                trim((string) ($d['cedula'] ?? ''))
            );
            if ($uid !== null) {
                $targetIds[$uid] = true;
            }
        }
        $targetIds = array_keys($targetIds);

        $userModels = $targetIds
            ? User::whereIn('id', $targetIds)->get()->keyBy('id')
            : collect();
        $profileModels = $targetIds
            ? CustomerProfile::whereIn('user_id', $targetIds)->get()->keyBy('user_id')
            : collect();
        $serviceModels = $targetIds
            ? UserService::whereIn('user_id', $targetIds)
                ->whereIn('status', [UserService::STATUS_ACTIVE, UserService::STATUS_GRATIS])
                ->get()
                ->keyBy('user_id')
            : collect();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $emailActual = trim((string) ($data['email_actual'] ?? ''));
            $cedulaActual = trim((string) ($data['cedula'] ?? ''));

            [$userId, $reason] = $this->resolveUserId($emailActual, $cedulaActual);
            if ($userId === null) {
                $message = match ($reason) {
                    'missing'   => 'Debes indicar email_actual o cedula para identificar al cliente',
                    'ambiguous' => "La cédula {$cedulaActual} corresponde a más de un cliente; usa email_actual para identificarlo",
                    default     => 'No existe cliente con ' . ($emailActual !== '' ? "email {$emailActual}" : "cédula {$cedulaActual}") . ' en este tenant',
                };
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => $emailActual !== '' ? 'email_actual' : 'cedula',
                    'error' => $message,
                ];
                continue;
            }

            $userPatch = [];
            $profilePatch = [];
            $newPlanId = null;

            if (!empty($data['nuevo_email'])) {
                $newEmail = trim((string) $data['nuevo_email']);
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'nuevo_email',
                        'error' => 'Email inválido',
                    ];
                    continue;
                }
                if (isset($this->existingEmails[$newEmail]) && $this->existingEmails[$newEmail] !== $userId) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'nuevo_email',
                        'error' => "El email {$newEmail} ya está en uso por otro cliente",
                    ];
                    continue;
                }
                if ($newEmail !== $emailActual) {
                    $userPatch['email'] = $newEmail;
                }
            }

            if (!empty($data['nombre'])) {
                $userPatch['user_name'] = trim((string) $data['nombre']);
                $profilePatch['name']   = trim((string) $data['nombre']);
            }
            if (!empty($data['apellido'])) {
                $userPatch['user_lastname'] = trim((string) $data['apellido']);
                $profilePatch['last_name']  = trim((string) $data['apellido']);
            }
            if (isset($data['telefono']) && $data['telefono'] !== '') {
                $userPatch['tel'] = (string) $data['telefono'];
            }

            if (!empty($data['password'])) {
                $pw = (string) $data['password'];
                if (strlen($pw) < 6) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'password',
                        'error' => 'La contraseña debe tener al menos 6 caracteres',
                    ];
                    continue;
                }
                $userPatch['password'] = Hash::make($pw);
            }

            foreach (['cedula' => 'cedula', 'direccion' => 'address', 'ciudad' => 'city'] as $excelKey => $dbKey) {
                if (isset($data[$excelKey]) && $data[$excelKey] !== '') {
                    $profilePatch[$dbKey] = (string) $data[$excelKey];
                }
            }

            if (!empty($data['ip_usuario'])) {
                $newIp = trim((string) $data['ip_usuario']);
                if (isset($this->ipToUserId[$newIp]) && $this->ipToUserId[$newIp] !== $userId) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'ip_usuario',
                        'error' => "La IP {$newIp} ya está asignada a otro cliente",
                    ];
                    continue;
                }
                $profilePatch['ip_user'] = $newIp;
            }

            if (!empty($data['ip_router'])) {
                $rip = trim((string) $data['ip_router']);
                if (!isset($this->routers[$rip])) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'ip_router',
                        'error' => "Router con IP {$rip} no existe en este tenant",
                    ];
                    continue;
                }
                $profilePatch['router_id'] = $this->routers[$rip];
            }

            if (!empty($data['nombre_plan'])) {
                $pn = (string) $data['nombre_plan'];
                if (!isset($this->plans[$pn])) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'nombre_plan',
                        'error' => "Plan '{$pn}' no existe en este tenant",
                    ];
                    continue;
                }
                $newPlanId = $this->plans[$pn];
                $profilePatch['service_id'] = $newPlanId;
            }

            if (!empty($data['nombre_sectorial'])) {
                $sn = (string) $data['nombre_sectorial'];
                if (!isset($this->sectorials[$sn])) {
                    $this->errors[] = [
                        'sheet' => 'Clientes',
                        'row' => $rowNumber,
                        'field' => 'nombre_sectorial',
                        'error' => "Sectorial '{$sn}' no existe",
                    ];
                    continue;
                }
                $profilePatch['sectorial_id'] = $this->sectorials[$sn];
            }

            if (isset($data['usuario_pppoe']) && $data['usuario_pppoe'] !== '') {
                $profilePatch['pppoe_username'] = (string) $data['usuario_pppoe'];
            }
            if (isset($data['password_pppoe']) && $data['password_pppoe'] !== '') {
                $profilePatch['pppoe_password'] = (string) $data['password_pppoe'];
            }

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
                $profilePatch['installation_date'] = $installationDate;
            }

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
                $profilePatch['estrato'] = $estrato;
            }

            if (empty($userPatch) && empty($profilePatch)) {
                continue;
            }

            try {
                if (!empty($userPatch)) {
                    // Eloquent (no query builder) para que User::booted() mantenga
                    // `name` derivado de user_name + user_lastname.
                    $user = $userModels->get($userId);
                    if ($user) {
                        $user->fill($userPatch);
                        $user->save();
                    }
                }

                if (!empty($profilePatch)) {
                    $profile = $profileModels->get($userId);
                    if ($profile) {
                        $profile->fill($profilePatch);
                        if ($newPlanId && isset($this->courtesyPlanIds[$newPlanId])) {
                            $profile->service_status = 'gratis';
                            $profile->status = true;
                        }
                        $profile->save();
                    }
                }

                if ($newPlanId) {
                    // Equivalente a UserService::syncForCustomer pero usando el
                    // estado de plan ya cacheado y el service precargado, para no
                    // hacer un Plan::find + SELECT por cada fila.
                    $status = isset($this->courtesyPlanIds[$newPlanId])
                        ? UserService::STATUS_GRATIS
                        : UserService::STATUS_ACTIVE;
                    $service = $serviceModels->get($userId);
                    if ($service) {
                        $service->update([
                            'service_plan_id' => $newPlanId,
                            'status' => $status,
                        ]);
                    } else {
                        $service = UserService::create([
                            'user_id' => $userId,
                            'service_plan_id' => $newPlanId,
                            'status' => $status,
                            'start_date' => now(),
                        ]);
                        $serviceModels->put($userId, $service);
                    }
                }

                // Refresh caches so subsequent rows see the new state.
                if (!empty($userPatch['email'])) {
                    unset($this->emailToUserId[$emailActual], $this->existingEmails[$emailActual]);
                    $this->emailToUserId[$userPatch['email']] = $userId;
                    $this->existingEmails[$userPatch['email']] = $userId;
                }
                if (!empty($profilePatch['ip_user'])) {
                    $oldIp = array_search($userId, $this->ipToUserId, true);
                    if ($oldIp !== false) {
                        unset($this->ipToUserId[$oldIp]);
                    }
                    $this->ipToUserId[$profilePatch['ip_user']] = $userId;
                }

                $this->updated++;
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => '-',
                    'error' => 'No se pudo actualizar: ' . $e->getMessage(),
                ];
            }
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
