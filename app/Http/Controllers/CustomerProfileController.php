<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerProfileController extends Controller
{
    /**
     * Display a list of customers profiles.
     */
    public function index()
    {
        $customers = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->select(
                'customer_profile.user_id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.department',
                'customer_profile.position',
                'users.email'
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
        ]);

        DB::beginTransaction();

        try {
            // create user in users table
            $user = User::create([
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
            $customer = CustomerProfile::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'last_name' => $data['last_name'],
                'department' => $data['department'] ?? null,
                'position' => $data['position'] ?? null,
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
            'email' => $user->email,
            'tel' => $user->tel,
            'email_tenant' => $user->email_tenant,
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
}
