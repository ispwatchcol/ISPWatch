<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use App\Models\User;
use App\Traits\FixesSequences;
use Illuminate\Support\Facades\DB;

class CustomerProfileController extends Controller
{
    use FixesSequences;
    /**
     * Display a list of customers profiles.
     */
    public function index()
    {
        $customers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->leftJoin('sectorial', 'customer_profile.sectorial_id', '=', 'sectorial.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->select(
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.department',
                'customer_profile.position',
                'customer_profile.ip_user',
                'customer_profile.service_id',
                'customer_profile.sectorial_id',
                'customer_profile.router_id',
                'customer_profile.status',
                'users.email',
                'service_plan.name as service_name',
                'sectorial.name as sectorial_name',
                'router.name as router_name'
            )
            ->get();

        return response()->json($customers);
    }

    /**
     * Get customer statistics and analytics.
     */
    public function statistics()
    {
        $totalCustomers = CustomerProfile::count();

        // Get customers from this month
        $startOfMonth = now()->startOfMonth();
        $newThisMonth = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.created_at', '>=', $startOfMonth)
            ->count();

        // Get customers from last month for growth calculation
        $startOfLastMonth = now()->subMonth()->startOfMonth();
        $endOfLastMonth = now()->subMonth()->endOfMonth();
        $lastMonthCustomers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->whereBetween('users.created_at', [$startOfLastMonth, $endOfLastMonth])
            ->count();

        // Calculate growth rate
        $growthRate = $lastMonthCustomers > 0
            ? round((($newThisMonth - $lastMonthCustomers) / $lastMonthCustomers) * 100, 1)
            : 0;

        // Active customers (assuming all are active for now, can be refined with activity tracking)
        $activeCustomers = $totalCustomers;

        // Distribution by department
        $byDepartment = CustomerProfile::select('department', \DB::raw('count(*) as count'))
            ->groupBy('department')
            ->orderBy('count', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'department' => $item->department ?: 'Sin departamento',
                    'count' => $item->count
                ];
            });

        // Distribution by position
        $byPosition = CustomerProfile::select('position', \DB::raw('count(*) as count'))
            ->groupBy('position')
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
                ->where('users.created_at', '<=', $monthEnd)
                ->count();

            $monthlyTrend[] = [
                'month' => $monthStart->locale('es')->format('M'),
                'count' => $count
            ];
        }

        // Recent customers (last 5)
        $recentCustomers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
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
     * Get customer locations for map display.
     */
    public function mapData()
    {
        $customers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
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
                'customer_profile.router_id',
                'users.email'
            )
            ->whereNotNull('customer_profile.latitude')
            ->whereNotNull('customer_profile.longitude')
            ->get();

        return response()->json($customers);
    }



    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'tel' => 'nullable|string|max:20',
            'email_tenant' => 'nullable|email',
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            // nuevos campos de servicio
            'ip_user' => 'nullable|string|max:45',
            'service_id' => 'nullable|integer|exists:service_plan,id',
            'sectorial_id' => 'nullable|integer|exists:sectorial,id',
            'router_id' => 'nullable|integer|exists:router,id',
            'tenant_id' => 'nullable|integer|exists:tenant,id',
        ]);

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

        DB::beginTransaction();

        try {
            // create user in users table
            $user = $this->createWithSequenceFix(User::class, [
                'user_name' => $data['name'],
                'user_lastname' => $data['last_name'],
                'email' => $data['email'],
                'email_tenant' => $data['email_tenant'] ?? null,
                'password' => $data['password'],
                'tel' => $data['tel'] ?? null,
                'role_id' => 3,
                'tenant_id' => 1,
                'status' => true,
            ]);

            // create customer profile in customer_profiles table
            $customer = $this->createWithSequenceFix(CustomerProfile::class, [
                'user_id' => $user->id,
                'name' => $data['name'],
                'last_name' => $data['last_name'],
                'department' => $data['department'] ?? null,
                'position' => $data['position'] ?? null,
                'ip_user' => $data['ip_user'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'sectorial_id' => $data['sectorial_id'] ?? null,
                'router_id' => $data['router_id'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Cliente creado correctamente. ✅',
                'customer' => $customer,
                'user' => $user
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el cliente.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified customer.
     */
    public function show($id)
    {
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();
        $user = User::findOrFail($id);

        return response()->json([
            'user_id' => $customer->user_id,
            'name' => $customer->name,
            'last_name' => $customer->last_name,
            'department' => $customer->department,
            'position' => $customer->position,
            'ip_user' => $customer->ip_user,
            'service_id' => $customer->service_id,
            'sectorial_id' => $customer->sectorial_id,
            'router_id' => $customer->router_id,
            'email' => $user->email,
            'tel' => $user->tel,
            'email_tenant' => $user->email_tenant,
        ]);
    }

    /**
     * Provision customer to Mikrotik Router.
     */
    public function provision($id, \App\Services\RouterApiService $routerApi)
    {
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

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

        $router = \App\Models\Router::find($customer->router_id);
        $servicePlan = \App\Models\Plan::find($customer->service_id);

        if (!$router) {
            return response()->json([
                'message' => 'Router asignado no encontrado.',
            ], 404);
        }

        $result = $routerApi->syncCustomer($router, $customer, $servicePlan);

        if ($result['success']) {
            return response()->json($result);
        } else {
            return response()->json($result, 500);
        }
    }

    /**
     * Bulk provision multiple customers to their assigned Mikrotik Routers.
     */
    public function bulkProvision(Request $request, \App\Services\RouterApiService $routerApi)
    {
        $data = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'integer|exists:customer_profile,user_id',
        ]);

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($data['customer_ids'] as $customerId) {
            $customer = CustomerProfile::where('user_id', $customerId)->first();

            if (!$customer) {
                $results[] = [
                    'customer_id' => $customerId,
                    'success' => false,
                    'message' => 'Cliente no encontrado',
                ];
                $failCount++;
                continue;
            }

            if (!$customer->router_id || !$customer->service_id) {
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => "{$customer->name} {$customer->last_name}",
                    'success' => false,
                    'message' => 'Cliente sin router o plan asignado',
                ];
                $failCount++;
                continue;
            }

            $router = \App\Models\Router::find($customer->router_id);
            $servicePlan = \App\Models\Plan::find($customer->service_id);

            if (!$router) {
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => "{$customer->name} {$customer->last_name}",
                    'success' => false,
                    'message' => 'Router no encontrado',
                ];
                $failCount++;
                continue;
            }

            $result = $routerApi->syncCustomer($router, $customer, $servicePlan);

            $results[] = [
                'customer_id' => $customerId,
                'customer_name' => "{$customer->name} {$customer->last_name}",
                'success' => $result['success'],
                'message' => $result['message'],
                'details' => $result['details'] ?? null,
            ];

            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return response()->json([
            'success' => $failCount === 0,
            'summary' => "Provisionados: {$successCount}, Fallidos: {$failCount}",
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results,
        ]);
    }

    /**
     * Suspend a customer (set status to false and add IP to router block list).
     */
    public function suspend($id, \App\Services\RouterApiService $routerApi)
    {
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

        if ($customer->status === false) {
            return response()->json([
                'message' => 'El cliente ya está suspendido.',
            ], 400);
        }

        // Update status
        $customer->update(['status' => false]);

        // If router assigned, add IP to block list
        if ($customer->router_id && $customer->ip_user) {
            $router = \App\Models\Router::find($customer->router_id);
            if ($router) {
                $result = $routerApi->addSuspendedIp(
                    $router,
                    $customer->ip_user,
                    "{$customer->name} {$customer->last_name}"
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Cliente suspendido correctamente.',
                    'router_result' => $result,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Cliente suspendido (sin router asignado).',
        ]);
    }

    /**
     * Activate a customer (set status to true and remove IP from router block list).
     */
    public function activate($id, \App\Services\RouterApiService $routerApi)
    {
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();

        if ($customer->status === true) {
            return response()->json([
                'message' => 'El cliente ya está activo.',
            ], 400);
        }

        // Update status
        $customer->update(['status' => true]);

        // If router assigned, remove IP from block list
        if ($customer->router_id && $customer->ip_user) {
            $router = \App\Models\Router::find($customer->router_id);
            if ($router) {
                $result = $routerApi->removeSuspendedIp($router, $customer->ip_user);

                return response()->json([
                    'success' => true,
                    'message' => 'Cliente activado correctamente.',
                    'router_result' => $result,
                ]);
            }
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
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();
        $user = User::findOrFail($id);

        $data = $request->validate([
            // user data
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:6',
            'tel' => 'nullable|string|max:20',
            'email_tenant' => 'nullable|email',

            // customer profile data
            'name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            // nuevos campos de servicio
            'ip_user' => 'nullable|string|max:45',
            'service_id' => 'nullable|integer|exists:service_plan,id',
            'sectorial_id' => 'nullable|integer|exists:sectorial,id',
            'router_id' => 'nullable|integer|exists:router,id',
        ]);

        DB::beginTransaction();

        try {
            // update user data
            $userData = [
                'user_name' => $data['name'] ?? $user->user_name,
                'user_lastname' => $data['last_name'] ?? $user->user_lastname,
                'email' => $data['email'] ?? $user->email,
                'email_tenant' => $data['email_tenant'] ?? $user->email_tenant,
                'tel' => $data['tel'] ?? $user->tel,
            ];

            if (!empty($data['password'])) {
                $userData['password'] = $data['password'];
            }

            $user->update($userData);

            // update customer profile data
            $customer->update([
                'name' => $data['name'] ?? $customer->name,
                'last_name' => $data['last_name'] ?? $customer->last_name,
                'department' => $data['department'] ?? $customer->department,
                'position' => $data['position'] ?? $customer->position,
                'ip_user' => array_key_exists('ip_user', $data) ? $data['ip_user'] : $customer->ip_user,
                'service_id' => array_key_exists('service_id', $data) ? $data['service_id'] : $customer->service_id,
                'sectorial_id' => array_key_exists('sectorial_id', $data) ? $data['sectorial_id'] : $customer->sectorial_id,
                'router_id' => array_key_exists('router_id', $data) ? $data['router_id'] : $customer->router_id,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Cliente actualizado correctamente. ✅',
                'customer' => $customer
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el cliente.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy($id)
    {
        $customer = CustomerProfile::where('user_id', $id)->firstOrFail();
        $user = User::findOrFail($id);

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
     * Get current tenant ID from authenticated user or request
     */
    private function getCurrentTenantId(Request $request): int
    {
        // Try to get from request header or query param
        if ($request->has('tenant_id')) {
            return (int) $request->input('tenant_id');
        }

        // Try to get from authenticated user
        if ($request->user() && $request->user()->tenant_id) {
            return $request->user()->tenant_id;
        }

        // Default to tenant 1 for backward compatibility
        return 1;
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
