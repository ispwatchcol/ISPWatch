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
        $customers = CustomerProfile::select(
            'user_id',
            'name',
            'last_name',
            'department',
            'position'
        )->get();

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
        return response()->json($customer);
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
