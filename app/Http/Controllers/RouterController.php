<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Router;

class RouterController extends Controller
{
    /**
     * Display a listing of the routers.
     */
    public function index()
    {
        $routers = Router::select(
            'id', 
            'name', 
            'ip', 
            'firmware_version', 
            'status', 
            'created_at')->get();
        
        return response()->json($routers);
    }

    /**
     * Store a newly created router in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'user_rb' => 'required|string|max:255',
            'password_rb' => 'required|string|max:255',
            'lan_interface' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => 'nullable|string|max:100',
            'status' => 'required|string|max:50',
            'coordinates' => 'nullable',
        ]);

        $router = Router::create($data);

        return response()->json([
            'message' => 'Router creado correctamente. ✅',
            'router' => $router,
        ], 201);
    }

    /**
     * Display the specified router.
     */
    public function show(Router $router)
    {
        return response()->json($router);
    }

    /**
     * Update the specified router in storage.
     */
    public function update(Request $request, Router $router)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'ip' => 'sometimes|ip',
            'user_rb' => 'sometimes|string|max:255',
            'password_rb' => 'sometimes|string|max:255',
            'lan_interface' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => 'nullable|string|max:100',
            'status' => 'sometimes|string|max:50',
            'coordinates' => 'nullable',
        ]);

        $router->update($data);

        return response()->json([
            'message' => 'Router actualizado correctamente. ✅',
            'router' => $router,
        ]);
    }

    /**
     * Remove the specified router from storage.
     */
    public function destroy(Router $router)
    {
        $router->delete();

        return response()->json([
            'message' => 'Router eliminado exitosamente. ✅',
        ]);
    }
}
