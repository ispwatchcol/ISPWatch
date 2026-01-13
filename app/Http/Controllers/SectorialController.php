<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Traits\FixesSequences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorialController extends Controller
{
    use FixesSequences;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sectorials = Sectorial::all();
        return response()->json($sectorials);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'user_rb' => 'nullable|string|max:255',
            'pass_rb' => 'nullable|string|max:255',
            'zona_id' => 'nullable|integer',
            'frequency' => 'nullable|integer',
            'node_tower' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'ssid' => 'nullable|string',
            'coordinates' => 'nullable|json',
        ]);

        try {
            $sectorial = $this->createWithSequenceFix(Sectorial::class, $data);

            return response()->json([
                'message' => 'Sectorial creado correctamente. ✅',
                'sectorial' => $sectorial
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el sectorial. ❌',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     *  Display the specified resource.
     */
    public function show($id)
    {
        try {
            $sectorial = Sectorial::findOrFail($id);
            return response()->json($sectorial);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Sectorial no encontrada',
                'error' => 'No se encontró una sectorial con el ID: ' . $id
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cargar la sectorial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $sectorial = Sectorial::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'ip' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'user_rb' => 'nullable|string|max:255',
            'pass_rb' => 'nullable|string|max:255',
            'zona_id' => 'nullable|integer',
            'frequency' => 'nullable|integer',
            'node_tower' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'ssid' => 'nullable|string',
            'coordinates' => 'nullable|json',
        ]);

        try {
            $sectorial->update($data);

            return response()->json([
                'message' => 'Sectorial actualizada correctamente. ✅',
                'sectorial' => $sectorial
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la sectorial.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $sectorial = Sectorial::findOrFail($id);
            $sectorial->delete();

            return response()->json([
                'message' => 'Sectorial eliminada correctamente. ✅'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la sectorial.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
