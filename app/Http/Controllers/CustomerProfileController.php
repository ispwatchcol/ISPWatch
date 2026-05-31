<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionCustomerJob;
use App\Models\BulkProvisionRun;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Sectorial;
use App\Services\CustomerProvisioningService;
use App\Services\MikroTikSshService;
use App\Services\RouterProvisioningService;
use App\Models\SuspensionActionLog;
use App\Http\Requests\StoreCustomerRequest;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UserService;
use App\Traits\FixesSequences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerProfileController extends Controller
{
    use FixesSequences;
    /**
     * Display a list of customers profiles (scoped to current tenant).
     */
    public function index(Request $request)
    {
        // Strict: always use authenticated user's tenant_id — ignore any query param
        // to prevent cross-tenant data leakage.
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'message' => 'No autorizado: usuario sin tenant asignado.',
            ], 401);
        }

        $customers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->leftJoin('sectorial', 'customer_profile.sectorial_id', '=', 'sectorial.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->where('users.tenant_id', $tenantId)
            ->select(
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.department',
                'customer_profile.position',
                'customer_profile.ip_user',
                'customer_profile.precinto',
                'customer_profile.service_id',
                'customer_profile.sectorial_id',
                'customer_profile.router_id',
                'customer_profile.status',
                'customer_profile.service_status',
                'customer_profile.pppoe_username',
                'users.email',
                'service_plan.name as service_name',
                'sectorial.name as sectorial_name',
                'router.name as router_name',
                'router.simple_queue as router_simple_queue',
                'router.control_pcq as router_control_pcq',
                'router.hotspot as router_hotspot',
                'router.pppoe as router_pppoe',
                'router.dhcp_leases as router_dhcp',
                'router.agregar_cliente_mkt as router_agregar_cliente_mkt',
                'router.falla_general as router_falla_general'
            )
            ->get();

        return response()->json($customers);
    }

    /**
     * Get customer statistics and analytics.
     */
    public function statistics()
    {
        // SECURITY (OWASP A01): CustomerProfile has no tenant_id of its own;
        // every aggregate must be scoped through users.tenant_id or it leaks
        // cross-tenant counts/PII.
        $tenantId = $this->authTenantId();

        $totalCustomers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->count();

        // Get customers from this month
        $startOfMonth = now()->startOfMonth();
        $newThisMonth = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->where('users.created_at', '>=', $startOfMonth)
            ->count();

        // Get customers from last month for growth calculation
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();
        $lastMonthCustomers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->whereBetween('users.created_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();

        // Calculate growth rate
        $growthRate = $lastMonthCustomers > 0
            ? round((($newThisMonth - $lastMonthCustomers) / $lastMonthCustomers) * 100, 1)
            : 0;

        // Active customers (assuming all are active for now, can be refined with activity tracking)
        $activeCustomers = $totalCustomers;

        // Distribution by department
        $byDepartment = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select('customer_profile.department', \DB::raw('count(*) as count'))
            ->groupBy('customer_profile.department')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'department' => $item->department ?: 'Sin departamento',
                    'count' => $item->count
                ];
            });

        // Distribution by position
        $byPosition = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select('customer_profile.position', \DB::raw('count(*) as count'))
            ->groupBy('customer_profile.position')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'position' => $item->position ?: 'Sin posición',
                    'count' => $item->count
                ];
            });

        // Monthly trend for last 6 months
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $count = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
                ->where('users.tenant_id', $tenantId)
                ->where('users.created_at', '<=', $monthEnd)
                ->count();

            $monthlyTrend[] = [
                'month' => $monthStart->locale('es')->format('M'),
                'count' => $count
            ];
        }

        // Recent customers (last 5)
        $recentCustomers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select(
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.department',
                'users.email'
            )
            ->orderBy('users.created_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_customers' => $totalCustomers,
            'new_this_month' => $newThisMonth,
            'growth_rate' => $growthRate,
            'active_customers' => $activeCustomers,
            'by_department' => $byDepartment,
            'by_position' => $byPosition,
            'monthly_trend' => $monthlyTrend,
            'recent_customers' => $recentCustomers
        ]);
    }

    /**
     * Get customer locations plus network nodes for the customer map.
     *
     * Returns customers (with service_status + sectorial_id for filtering and
     * traceability), routers/nodes and sectorials (with type + coverage radius)
     * so the frontend can render heatmaps, customer↔sectorial traceability
     * lines and coverage zones. Routers and sectorials are tenant-scoped via
     * the BelongsToTenant global scope.
     */
    public function mapData()
    {
        $tenantId = $this->authTenantId();

        $customers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select(
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.department',
                'customer_profile.position',
                'customer_profile.address',
                'customer_profile.city',
                'customer_profile.state',
                'customer_profile.country',
                'customer_profile.latitude',
                'customer_profile.longitude',
                'customer_profile.sectorial_id',
                'customer_profile.router_id',
                'customer_profile.service_status',
                'users.email'
            )
            ->whereNotNull('customer_profile.latitude')
            ->whereNotNull('customer_profile.longitude')
            ->get();

        // Routers / nodes. coordinates is a json column that, depending on how
        // it was saved, holds either a {lat,lng} object or a WKT POINT string.
        $routers = Router::query()
            ->get(['id', 'name', 'coordinates'])
            ->map(function ($router) {
                $coords = $this->parseRouterCoordinates($router->coordinates);
                if (!$coords) {
                    return null;
                }
                return [
                    'id' => $router->id,
                    'name' => $router->name,
                    'latitude' => $coords['lat'],
                    'longitude' => $coords['lng'],
                ];
            })
            ->filter()
            ->values();

        // Sectorials. The model accessor returns ['lat'=>, 'lng'=>] (or null).
        $sectorials = Sectorial::query()
            ->get(['id', 'name', 'type', 'coordinates', 'coverage_radius_meters', 'antenna_type', 'frequency', 'node_tower'])
            ->map(function ($sectorial) {
                $coords = $sectorial->coordinates;
                if (!is_array($coords) || !isset($coords['lat'], $coords['lng'])) {
                    return null;
                }
                return [
                    'id' => $sectorial->id,
                    'name' => $sectorial->name,
                    'type' => $sectorial->type,
                    'antenna_type' => $sectorial->antenna_type,
                    'latitude' => (float) $coords['lat'],
                    'longitude' => (float) $coords['lng'],
                    'coverage_radius_meters' => $sectorial->coverage_radius_meters
                        ? (int) $sectorial->coverage_radius_meters
                        : null,
                    'frequency' => $sectorial->frequency,
                    'node_tower' => $sectorial->node_tower,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'customers' => $customers,
            'routers' => $routers,
            'sectorials' => $sectorials,
        ]);
    }

    /**
     * Normalize a router's `coordinates` value (json {lat,lng} object or a
     * WKT "POINT(lng lat)" string) into ['lat'=>float,'lng'=>float] or null.
     */
    private function parseRouterCoordinates($coords): ?array
    {
        $lat = null;
        $lng = null;

        if (is_array($coords)) {
            $lat = $coords['lat'] ?? $coords['latitude'] ?? null;
            $lng = $coords['lng'] ?? $coords['lon'] ?? $coords['longitude'] ?? null;
        } elseif (is_string($coords) && $coords !== '') {
            if (preg_match('/POINT\s*\(\s*(-?[\d.]+)\s+(-?[\d.]+)\s*\)/i', $coords, $m)) {
                // WKT is POINT(lng lat).
                $lng = $m[1];
                $lat = $m[2];
            } else {
                $decoded = json_decode($coords, true);
                if (is_array($decoded)) {
                    $lat = $decoded['lat'] ?? $decoded['latitude'] ?? null;
                    $lng = $decoded['lng'] ?? $decoded['lon'] ?? $decoded['longitude'] ?? null;
                }
            }
        }

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }



    /**
     * Store a newly created customer in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();

        // Get tenant ID from request or session
        $tenantId = $data['tenant_id'] ?? $this->getCurrentTenantId($request);

        // Check customer limit for tenant
        $limitCheck = $this->checkCustomerLimit($tenantId);
        if (!$limitCheck['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $limitCheck['message'],
                'limit' => $limitCheck['limit'],
                'current' => $limitCheck['current'],
                'upgrade_required' => true,
            ], 403);
        }

        // email_tenant = correo de ACCESO (login), distinto del correo personal.
        // El operador puede fijarlo al crear; si lo deja vacío se autogenera como
        // nombre.apellido@dominio-del-tenant.
        if (!empty($data['email_tenant'])) {
            $emailTenant = strtolower(trim($data['email_tenant']));
        } else {
            $tenant = \App\Models\Tenant::find($tenantId);
            $firstName = strtolower(preg_replace('/\s+/', '', $data['name'] ?? ''));
            $lastName  = strtolower(preg_replace('/\s+/', '', $data['last_name'] ?? ''));
            $domain    = $tenant ? strtolower($tenant->domain) : 'local';
            $emailTenant = "{$firstName}.{$lastName}@{$domain}";
        }

        DB::beginTransaction();

        try {
            // create user in users table
            $user = $this->createWithSequenceFix(User::class, [
                'name'         => trim(($data['name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'user_name'    => $data['name'],
                'user_lastname'=> $data['last_name'],
                'email'        => $data['email'],
                'email_tenant' => $emailTenant,
                'password'     => bcrypt($data['password']),
                'tel'          => $data['tel'] ?? null,
                'role_id'      => \App\Models\Role::idByName('Cliente', $tenantId) ?? 3,
                'tenant_id'    => $tenantId,
                'status'       => true,
            ]);

            // create customer profile
            $customer = $this->createWithSequenceFix(CustomerProfile::class, [
                'user_id'     => $user->id,
                'name'        => $data['name'],
                'last_name'   => $data['last_name'],
                'cedula'      => $data['cedula'] ?? null,
                'city'        => $data['city'] ?? null,
                'state'       => $data['state'] ?? null,
                'address'     => $data['address'] ?? null,
                'precinto'    => $data['precinto'] ?? null,
                'installation_date' => $data['installation_date'] ?? null,
                'estrato'     => $data['estrato'] ?? null,
                'ip_user'        => $data['ip_user'] ?? null,
                'service_id'     => $data['service_id'] ?? null,
                'sectorial_id'   => $data['sectorial_id'] ?? null,
                'router_id'      => $data['router_id'] ?? null,
                'pppoe_username' => $data['pppoe_username'] ?? null,
                'pppoe_password' => $data['pppoe_password'] ?? null,
                'pppoe_local_address' => $data['pppoe_local_address'] ?? null,
                'hotspot_username' => $data['hotspot_username'] ?? null,
                'hotspot_password' => $data['hotspot_password'] ?? null,
                'mac_address'    => $data['mac_address'] ?? null,
                'service_status' => (!empty($data['service_id']) && optional(Plan::find($data['service_id']))->is_courtesy)
                    ? 'gratis'
                    : 'activo',
            ]);

            // Mirror the assigned plan into user_services so the monthly
            // billing job sees this customer. Courtesy plans land as 'gratis'
            // and are never auto-invoiced.
            UserService::syncForCustomer($user->id, $data['service_id'] ?? null);

            DB::commit();

            // ── Auto-aprovisionamiento a la RB según el MÉTODO DE CONTROL ──
            // Una sola compuerta: el router debe tener `agregar_cliente_mkt`.
            // El dispatcher (CustomerProvisioningService) elige queue / pppoe /
            // pcq / hotspot / dhcp según el modo excluyente del router.
            $router      = !empty($data['router_id']) ? Router::find($data['router_id']) : null;
            $servicePlan = !empty($data['service_id']) ? Plan::find($data['service_id']) : null;
            $provision   = null;

            if ($router && !$router->agregar_cliente_mkt) {
                \Log::info('[CustomerProfile] Skip auto-provision: agregar_cliente_mkt disabled on router', [
                    'router_id' => $router->id,
                ]);
            } elseif ($router && $servicePlan && $customer->ip_user) {
                try {
                    $provision = app(CustomerProvisioningService::class)
                        ->provisionByControlMode($router, $customer, $servicePlan);

                    if (!($provision['success'] ?? false)) {
                        \Log::warning('[CustomerProfile] Auto-provision incompleto (no bloqueante)', [
                            'customer_id' => $customer->user_id,
                            'mode'        => $provision['mode'] ?? null,
                            'message'     => $provision['message'] ?? null,
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('[CustomerProfile] Auto-provision exception (no bloqueante)', [
                        'error' => $e->getMessage(),
                    ]);
                    $provision = ['success' => false, 'message' => $e->getMessage()];
                }
            }

            return response()->json([
                'message'            => 'Cliente creado correctamente. ✅',
                'customer'           => $customer,
                'user'               => $user,
                'email_tenant'       => $emailTenant,
                'queue_provisioned'  => $provision['queue_result'] ?? null,
                'pppoe_provisioned'  => $provision['pppoe_result'] ?? null,
                'provision'          => $provision,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Create customer failed', [
                'error'    => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message'  => 'Error al crear el cliente.',
                'error'    => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified customer.
     */
    public function show($id)
    {
        $authTenantId = auth()->user()?->tenant_id;
        if (!$authTenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::where('tenant_id', $authTenantId)->findOrFail($id);
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

        return response()->json([
            'user_id'      => $customer->user_id,
            'name'         => $customer->name,
            'last_name'    => $customer->last_name,
            'cedula'       => $customer->cedula,
            'city'         => $customer->city,
            'state'        => $customer->state,
            'address'      => $customer->address,
            'precinto'     => $customer->precinto,
            'installation_date' => $customer->installation_date,
            'estrato'      => $customer->estrato,
            'latitude'     => $customer->latitude,
            'longitude'    => $customer->longitude,
            'ip_user'      => $customer->ip_user,
            'service_id'   => $customer->service_id,
            'sectorial_id' => $customer->sectorial_id,
            'router_id'    => $customer->router_id,
            'status'         => $customer->status,
            'service_status' => $customer->service_status ?: ($customer->status ? 'activo' : 'suspendido'),
            'pppoe_username' => $customer->pppoe_username,
            'pppoe_password' => $customer->pppoe_password,
            'pppoe_local_address' => $customer->pppoe_local_address,
            'hotspot_username' => $customer->hotspot_username,
            'hotspot_password' => $customer->hotspot_password,
            'mac_address'    => $customer->mac_address,
            'email'          => $user->email,
            'tel'            => $user->tel,
            'email_tenant'   => $user->email_tenant,
        ]);
    }

    /**
     * Provision customer to Mikrotik Router.
     * Uses MikroTikSshService.syncQueueViaCore which works from production (DigitalOcean)
     */
    public function provision($id)
    {
        $customer = $this->findTenantCustomerOrFail($id);

        if (!$customer->router_id) {
            return response()->json([
                'message' => 'El cliente no tiene un router asignado.',
            ], 400);
        }

        if (!$customer->service_id) {
            return response()->json([
                'message' => 'El cliente no tiene un plan de servicio asignado.',
            ], 400);
        }

        if (!$customer->ip_user) {
            return response()->json([
                'message' => 'El cliente no tiene una IP asignada.',
            ], 400);
        }

        $router = Router::find($customer->router_id);
        $servicePlan = Plan::find($customer->service_id);

        if (!$router) {
            return response()->json([
                'message' => 'Router asignado no encontrado.',
            ], 404);
        }

        if (!$servicePlan) {
            return response()->json([
                'message' => 'Plan de servicio no encontrado.',
            ], 404);
        }

        // Acción explícita del operador: aprovisiona según el método de control
        // del router (no depende de agregar_cliente_mkt).
        $provision = app(CustomerProvisioningService::class)
            ->provisionByControlMode($router, $customer, $servicePlan);

        $success = (bool) ($provision['success'] ?? false);

        return response()->json([
            'success'      => $success,
            'message'      => $success
                ? 'Provisionado correctamente.'
                : ($provision['message'] ?? 'Provisionado con advertencias.'),
            'mode'         => $provision['mode'] ?? null,
            'queue_result' => $provision['queue_result'] ?? null,
            'pppoe_result' => $provision['pppoe_result'] ?? null,
            'provision'    => $provision,
        ], $success ? 200 : 500);
    }

    /**
     * Bulk provision multiple customers to their assigned Mikrotik Routers.
     * Uses MikroTikSshService.syncQueueViaCore which works from production (DigitalOcean)
     */
    public function bulkProvision(Request $request, CustomerProvisioningService $provisioner)
    {
        set_time_limit(600);

        $data = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'integer|exists:customer_profile,user_id',
        ]);

        $tenantId = $this->authTenantId();

        $results           = [];
        $successCount      = 0;
        $failCount         = 0;
        $pppoeSkippedCount = 0;

        foreach ($data['customer_ids'] as $customerId) {
            $row = $provisioner->provisionOne((int) $customerId, $tenantId);
            $results[] = $row;

            if (!empty($row['success'])) {
                $successCount++;
            } else {
                $failCount++;
            }
            if (!empty($row['pppoe_skipped'])) {
                $pppoeSkippedCount++;
            }
        }

        return response()->json([
            'success'             => $failCount === 0,
            'success_count'       => $successCount,
            'fail_count'          => $failCount,
            'pppoe_skipped_count' => $pppoeSkippedCount,
            'results'             => $results,
        ]);
    }

    /**
     * Inicia el aprovisionamiento masivo en SEGUNDO PLANO.
     *
     * Cada cliente tarda ~17-34s (SSH al CORE → SSH anidado al router), así que
     * hacerlo síncrono revienta el cap de ~60s del gateway. Aquí solo creamos el
     * registro de progreso y despachamos un job por cliente; el worker los
     * procesa secuencialmente y el frontend consulta el avance con bulkProvisionStatus.
     */
    public function bulkProvisionAsync(Request $request)
    {
        $data = $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:customer_profile,user_id',
        ]);

        $tenantId = $this->authTenantId();
        $ids = array_values(array_unique(array_map('intval', $data['customer_ids'])));

        $run = BulkProvisionRun::create([
            'id'        => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'status'    => 'processing',
            'total'     => count($ids),
            'processed' => 0,
            'results'   => [],
        ]);

        foreach ($ids as $customerId) {
            ProvisionCustomerJob::dispatch($run->id, $customerId, $tenantId);
        }

        return response()->json([
            'job_id' => $run->id,
            'status' => $run->status,
            'total'  => $run->total,
        ], 202);
    }

    /**
     * Progreso/resultados de una corrida de aprovisionamiento masivo.
     * Tenant-scoped: una corrida de otro tenant resuelve 404.
     */
    public function bulkProvisionStatus(string $jobId)
    {
        $tenantId = $this->authTenantId();

        $run = BulkProvisionRun::where('id', $jobId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$run) {
            return response()->json(['message' => 'Corrida de aprovisionamiento no encontrada.'], 404);
        }

        return response()->json([
            'job_id'              => $run->id,
            'status'              => $run->status,
            'total'               => $run->total,
            'processed'           => $run->processed,
            'success_count'       => $run->success_count,
            'fail_count'          => $run->fail_count,
            'pppoe_skipped_count' => $run->pppoe_skipped_count,
            'results'             => $run->results ?? [],
        ]);
    }

    /**
     * Suspend a customer (set status to false and add IP to router block list).
     */
    public function suspend($id)
    {
        $customer = $this->findTenantCustomerOrFail($id);

        if ($customer->status === false) {
            return response()->json([
                'message' => 'El cliente ya está suspendido.',
            ], 400);
        }

        // Update status (DB = estado deseado; la RB se reconcilia aparte)
        $customer->update(['status' => false, 'service_status' => 'suspendido']);

        // If router assigned, add IP to block list via the provisioning service
        // so the attempt is recorded in suspension_action_logs (failover/sync).
        if ($customer->router_id && $customer->ip_user) {
            $ok = app(RouterProvisioningService::class)->suspendCustomer(
                (int) $customer->user_id,
                (int) $customer->router_id,
                ['reason' => SuspensionActionLog::REASON_MANUAL]
            );

            return response()->json([
                'success' => $ok,
                'message' => $ok
                    ? 'Cliente suspendido correctamente.'
                    : 'Cliente suspendido en BD pero error en router: ' . $this->lastRouterError($customer->user_id),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cliente suspendido (sin router asignado).',
        ]);
    }

    /**
     * Last router error logged for a customer (for the suspend/activate response).
     */
    private function lastRouterError(int $customerId): string
    {
        $log = SuspensionActionLog::where('customer_id', $customerId)
            ->latest('id')
            ->first();

        return $log?->error_message ?: 'desconocido';
    }

    /**
     * Activate a customer (set status to true and remove IP from router block list).
     */
    public function activate($id)
    {
        $customer = $this->findTenantCustomerOrFail($id);

        if ($customer->status === true) {
            return response()->json([
                'message' => 'El cliente ya está activo.',
            ], 400);
        }

        // Update status — courtesy plans stay 'gratis'
        $activePlan = $customer->service_id ? Plan::find($customer->service_id) : null;
        $customer->update([
            'status' => true,
            'service_status' => ($activePlan && $activePlan->is_courtesy) ? 'gratis' : 'activo',
        ]);

        // If router assigned, remove IP from block list via the provisioning
        // service so the attempt is recorded in suspension_action_logs.
        if ($customer->router_id && $customer->ip_user) {
            $ok = app(RouterProvisioningService::class)->unsuspendCustomer(
                (int) $customer->user_id,
                (int) $customer->router_id,
                ['reason' => SuspensionActionLog::REASON_MANUAL]
            );

            return response()->json([
                'success' => $ok,
                'message' => $ok
                    ? 'Cliente activado correctamente.'
                    : 'Cliente activado en BD pero error en router: ' . $this->lastRouterError($customer->user_id),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cliente activado (sin router asignado).',
        ]);
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, $id)
    {
        $authTenantId = auth()->user()?->tenant_id;
        if (!$authTenantId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = User::where('tenant_id', $authTenantId)->findOrFail($id);
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

        $data = $request->validate([
            // user data
            'email'    => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:6',
            'tel'      => 'nullable|string|max:20',

            // customer profile data
            'name'      => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'cedula'    => 'nullable|string|max:20',
            'city'      => 'nullable|string|max:255',
            'state'     => 'nullable|string|max:255',
            'address'   => 'nullable|string|max:500',
            'precinto'  => 'nullable|string|max:100',
            'installation_date' => 'nullable|date',
            'estrato'   => 'nullable|integer|between:1,6',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            // service configuration
            'ip_user'      => 'nullable|string|max:45',
            'service_id'   => 'nullable|integer|exists:service_plan,id',
            'sectorial_id' => 'nullable|integer|exists:sectorial,id',
            'router_id'    => 'nullable|integer|exists:router,id',

            // PPPoE secret (optional)
            'create_pppoe_secret' => 'nullable|boolean',
            'pppoe_username'      => 'nullable|string|max:255',
            'pppoe_password'      => 'nullable|string|max:255',
            'pppoe_local_address' => 'nullable|string|max:45',

            // HotSpot credentials / MAC
            'hotspot_username'    => 'nullable|string|max:255',
            'hotspot_password'    => 'nullable|string|max:255',
            'mac_address'         => 'nullable|string|max:17|regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',

            // Service state
            'service_status' => 'nullable|in:activo,suspendido,cancelado,gratis',
        ]);

        DB::beginTransaction();

        try {
            // update user data
            $userData = [
                'user_name'    => $data['name'] ?? $user->user_name,
                'user_lastname'=> $data['last_name'] ?? $user->user_lastname,
                'email'        => $data['email'] ?? $user->email,
                'tel'          => $data['tel'] ?? $user->tel,
            ];

            if (!empty($data['password'])) {
                $userData['password'] = \Illuminate\Support\Facades\Hash::make($data['password']);
            }

            $user->update($userData);

            // Resolve the service state. A courtesy plan always forces 'gratis';
            // 'gratis' is otherwise invalid and falls back to 'activo'. The
            // legacy boolean `status` is kept in sync so the customer list,
            // router block-list and billing keep working.
            $prevStatusBool = (bool) $customer->status;
            $effectiveServiceId = array_key_exists('service_id', $data)
                ? $data['service_id']
                : $customer->service_id;
            $effectivePlan = $effectiveServiceId ? Plan::find($effectiveServiceId) : null;

            $requestedStatus = (array_key_exists('service_status', $data) && $data['service_status'])
                ? $data['service_status']
                : ($customer->service_status ?: ($customer->status ? 'activo' : 'suspendido'));

            if ($effectivePlan && $effectivePlan->is_courtesy) {
                $serviceStatus = 'gratis';
            } elseif ($requestedStatus === 'gratis') {
                $serviceStatus = 'activo';
            } else {
                $serviceStatus = $requestedStatus;
            }

            $statusBool = in_array($serviceStatus, ['activo', 'gratis'], true);

            // update customer profile data
            $customer->update([
                'name'        => $data['name'] ?? $customer->name,
                'last_name'   => $data['last_name'] ?? $customer->last_name,
                'cedula'      => array_key_exists('cedula', $data) ? $data['cedula'] : $customer->cedula,
                'city'        => array_key_exists('city', $data) ? $data['city'] : $customer->city,
                'state'       => array_key_exists('state', $data) ? $data['state'] : $customer->state,
                'address'     => array_key_exists('address', $data) ? $data['address'] : $customer->address,
                'precinto'    => array_key_exists('precinto', $data) ? $data['precinto'] : $customer->precinto,
                'installation_date' => array_key_exists('installation_date', $data) ? $data['installation_date'] : $customer->installation_date,
                'estrato'     => array_key_exists('estrato', $data) ? ($data['estrato'] !== '' ? $data['estrato'] : null) : $customer->estrato,
                'latitude'    => array_key_exists('latitude', $data) ? ($data['latitude'] !== '' ? $data['latitude'] : null) : $customer->latitude,
                'longitude'   => array_key_exists('longitude', $data) ? ($data['longitude'] !== '' ? $data['longitude'] : null) : $customer->longitude,
                'ip_user'     => array_key_exists('ip_user', $data) ? $data['ip_user'] : $customer->ip_user,
                'service_id'  => array_key_exists('service_id', $data) ? $data['service_id'] : $customer->service_id,
                'sectorial_id'=> array_key_exists('sectorial_id', $data) ? $data['sectorial_id'] : $customer->sectorial_id,
                'router_id'      => array_key_exists('router_id', $data) ? $data['router_id'] : $customer->router_id,
                'pppoe_username' => array_key_exists('pppoe_username', $data) ? $data['pppoe_username'] : $customer->pppoe_username,
                'pppoe_password' => array_key_exists('pppoe_password', $data) ? $data['pppoe_password'] : $customer->pppoe_password,
                'pppoe_local_address' => array_key_exists('pppoe_local_address', $data) ? $data['pppoe_local_address'] : $customer->pppoe_local_address,
                'hotspot_username' => array_key_exists('hotspot_username', $data) ? $data['hotspot_username'] : $customer->hotspot_username,
                'hotspot_password' => array_key_exists('hotspot_password', $data) ? $data['hotspot_password'] : $customer->hotspot_password,
                'mac_address'    => array_key_exists('mac_address', $data) ? $data['mac_address'] : $customer->mac_address,
                'service_status' => $serviceStatus,
                'status'         => $statusBool,
            ]);

            // Keep user_services aligned with the (possibly changed) plan so
            // billing/courtesy status stays correct after an edit.
            UserService::syncForCustomer($id, $effectiveServiceId ? (int) $effectiveServiceId : null);

            DB::commit();

            // Re-sincronizar el cliente en la RB tras la edición, según el
            // MÉTODO DE CONTROL del router (solo si el router auto-provisiona).
            // Mantiene la config de la RB al día tras cambiar plan/IP/MAC/creds.
            $provision     = null;
            $provRouterId  = $data['router_id'] ?? $customer->router_id;
            $provServiceId = $data['service_id'] ?? $customer->service_id;
            if ($provRouterId && $provServiceId && $customer->ip_user) {
                $provRouter = Router::find($provRouterId);
                $provPlan   = Plan::find($provServiceId);
                if ($provRouter && $provRouter->agregar_cliente_mkt && $provPlan) {
                    try {
                        $provision = app(CustomerProvisioningService::class)
                            ->provisionByControlMode($provRouter, $customer, $provPlan);
                    } catch (\Throwable $e) {
                        \Log::warning('[CustomerProfile] Update auto-provision exception (no bloqueante)', [
                            'error' => $e->getMessage(),
                        ]);
                        $provision = ['success' => false, 'message' => $e->getMessage()];
                    }
                }
            }

            // Sync router block-list when the active/blocked state changed.
            // Delegated to RouterProvisioningService so the attempt is recorded
            // in suspension_action_logs (failover/sync) — non-blocking.
            $statusRouterResult = null;
            if ($statusBool !== $prevStatusBool && $customer->router_id && $customer->ip_user) {
                try {
                    $prov = app(RouterProvisioningService::class);
                    $ok = $statusBool
                        // Now activo/gratis -> unblock
                        ? $prov->unsuspendCustomer((int) $customer->user_id, (int) $customer->router_id, ['reason' => SuspensionActionLog::REASON_MANUAL])
                        // Now suspendido/cancelado -> block
                        : $prov->suspendCustomer((int) $customer->user_id, (int) $customer->router_id, ['reason' => SuspensionActionLog::REASON_MANUAL]);

                    $statusRouterResult = [
                        'success' => $ok,
                        'message' => $ok ? 'OK' : $this->lastRouterError($customer->user_id),
                    ];
                } catch (\Throwable $e) {
                    \Log::warning('[CustomerProfile] Status router sync exception (non-blocking)', [
                        'error' => $e->getMessage(),
                    ]);
                    $statusRouterResult = ['success' => false, 'message' => $e->getMessage()];
                }
            }

            return response()->json([
                'message'           => 'Cliente actualizado correctamente. ✅',
                'customer'          => $customer,
                'pppoe_provisioned' => $provision['pppoe_result'] ?? null,
                'provision'         => $provision,
                'status_router'     => $statusRouterResult,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el cliente.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy($id)
    {
        $tenantId = $this->authTenantId();
        // Tenant-scoped lookups: a cross-tenant id resolves to 404, never deletes.
        $user = User::where('tenant_id', $tenantId)->findOrFail($id);
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

        DB::beginTransaction();

        try {
            $customer->delete();
            $user->delete();

            DB::commit();

            return response()->json([
                'message' => 'Cliente eliminado correctamente. ✅'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar el cliente.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve the authenticated user's tenant id or abort.
     *
     * SECURITY (OWASP A01): single source of truth for tenant scoping.
     */
    private function authTenantId(): int
    {
        $tenantId = auth()->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado: usuario sin tenant asignado.');
        return (int) $tenantId;
    }

    /**
     * Resolve a customer by id, asserting it belongs to the authenticated
     * user's tenant. Prevents cross-tenant IDOR (OWASP A01).
     */
    private function findTenantCustomerOrFail($id): CustomerProfile
    {
        $tenantId = $this->authTenantId();
        // findOrFail throws 404 when the user belongs to another tenant,
        // so cross-tenant ids are indistinguishable from non-existent ones.
        User::where('tenant_id', $tenantId)->findOrFail($id);
        return CustomerProfile::where('user_id', $id)->firstOrFail();
    }

    /**
     * Get current tenant ID from authenticated user.
     *
     * SECURITY FIX (OWASP A01): Never accept tenant_id from request input.
     * Always derive from the authenticated user session.
     */
    private function getCurrentTenantId(Request $request): int
    {
        // Always get from authenticated user
        if ($request->user() && $request->user()->tenant_id) {
            return $request->user()->tenant_id;
        }

        // No fallback — if we reach here, authentication is broken
        abort(403, 'No se pudo determinar el tenant del usuario autenticado.');
    }

    /**
     * Check if tenant has reached customer limit
     */
    private function checkCustomerLimit(int $tenantId): array
    {
        $tenant = \App\Models\Tenant::find($tenantId);

        if (!$tenant) {
            return [
                'allowed' => false,
                'message' => 'Tenant no encontrado.',
                'limit' => 0,
                'current' => 0,
            ];
        }

        // If max_customers is 0 or null, unlimited
        if (empty($tenant->max_customers) || $tenant->max_customers <= 0) {
            return [
                'allowed' => true,
                'message' => 'Sin límite de clientes.',
                'limit' => 0,
                'current' => 0,
            ];
        }

        // Count current customers for this tenant
        $currentCount = CustomerProfile::whereHas('user', function ($query) use ($tenantId) {
            $query->where('tenant_id', $tenantId);
        })->count();

        if ($currentCount >= $tenant->max_customers) {
            return [
                'allowed' => false,
                'message' => "Has alcanzado el límite de {$tenant->max_customers} clientes de tu plan {$tenant->status}. Contacta con soporte para actualizar tu plan.",
                'limit' => $tenant->max_customers,
                'current' => $currentCount,
            ];
        }

        return [
            'allowed' => true,
            'message' => 'OK',
            'limit' => $tenant->max_customers,
            'current' => $currentCount,
            'remaining' => $tenant->max_customers - $currentCount,
        ];
    }
}
