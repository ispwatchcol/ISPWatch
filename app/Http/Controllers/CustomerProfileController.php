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
            'id', 
            'user_name', 
            'email', 
            'role_id', 
            'created_at')->get();

        return response()->json($customers);
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_name' => 'required|string|max:255',
            'email' => 'required|email|unique:customer_profile,email',
            'role_id' => 'required|integer',
        ]);

        $customer = CustomerProfile::create($data);

        return response()->json([
            'message' => 'Cliente creado correctamente. ✅',
            'customer' => $customer], 201);
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
            'user_name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|email|unique:customer_profile,email,' . $customer->id,
            'role_id' => 'sometimes|integer',
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
