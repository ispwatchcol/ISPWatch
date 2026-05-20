<?php
namespace App\Imports;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Sectorial;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            ->select('user_id', 'ip_user')
            ->get();
        foreach ($profiles as $p) {
            if ($p->ip_user) {
                $this->ipToUserId[$p->ip_user] = $p->user_id;
            }
        }
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $emailActual = trim((string) ($data['email_actual'] ?? ''));
            if ($emailActual === '') {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'email_actual',
                    'error' => 'El email_actual es obligatorio para identificar al cliente',
                ];
                continue;
            }

            if (!isset($this->emailToUserId[$emailActual])) {
                $this->errors[] = [
                    'sheet' => 'Clientes',
                    'row' => $rowNumber,
                    'field' => 'email_actual',
                    'error' => "No existe cliente con email {$emailActual} en este tenant",
                ];
                continue;
            }
            $userId = $this->emailToUserId[$emailActual];

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

            if (empty($userPatch) && empty($profilePatch)) {
                continue;
            }

            $courtesyPlanIds = $this->courtesyPlanIds;

            try {
                DB::transaction(function () use ($userId, $userPatch, $profilePatch, $newPlanId, $courtesyPlanIds) {
                    if (!empty($userPatch)) {
                        // Use Eloquent so User::booted() keeps `name` derived from
                        // user_name + user_lastname.
                        $user = User::find($userId);
                        $user->fill($userPatch);
                        $user->save();
                    }

                    if (!empty($profilePatch)) {
                        $profile = CustomerProfile::where('user_id', $userId)->first();
                        if ($profile) {
                            $profile->fill($profilePatch);
                            if ($newPlanId && isset($courtesyPlanIds[$newPlanId])) {
                                $profile->service_status = 'gratis';
                                $profile->status = true;
                            }
                            $profile->save();
                        }
                    }

                    if ($newPlanId) {
                        UserService::syncForCustomer($userId, (int) $newPlanId);
                    }
                });

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
}
