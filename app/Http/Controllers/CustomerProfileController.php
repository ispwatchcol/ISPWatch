<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use Illuminate\Http\Request;

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
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
        ]);

        $lastUserId = CustomerProfile::max('user_id') ?? 0;
        $data['user_id'] = $lastUserId + 1;

        $customer = CustomerProfile::create($data);

        return response()->json([
            'message' => 'Cliente creado correctamente. ✅',
            'customer' => $customer
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show(CustomerProfile $customer)
    {
        return response()->json($customer);
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, CustomerProfile $customer)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'department' => 'sometimes|nullable|string|max:255',
            'position' => 'sometimes|nullable|string|max:255',
        ]);

        $customer->update($data);

        return response()->json([
            'message' => 'Cliente actualizado correctamente. ✅',
            'customer' => $customer
        ]);
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy(CustomerProfile $customer)
    {
        $customer->delete();

        return response()->json([
            'message' => 'Cliente eliminado correctamente. ✅',
        ]);
    }
}
