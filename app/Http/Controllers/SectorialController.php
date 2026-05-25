<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Models\SectorialHistory;
use App\Traits\FixesSequences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorialController extends Controller
{
    use FixesSequences;

    public function index(Request $request)
    {
        $query = Sectorial::query();

        $tenantId = $request->user()?->tenant_id;
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($request->filled('element_type') && $request->element_type !== 'all') {
            $query->where('element_type', $request->element_type);
        }

        $sectorials = $query->orderBy('created_at', 'desc')->get();
        return response()->json($sectorials);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'element_type' => 'nullable|in:sectorial,switch,nodo',
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

        $data['element_type'] = $data['element_type'] ?? Sectorial::ELEMENT_SECTORIAL;

        try {
            $sectorial = $this->createWithSequenceFix(Sectorial::class, $data);

            SectorialHistory::log(
                $sectorial->id,
                'created',
                'Se creó el elemento "' . $sectorial->name . '" (' . $sectorial->element_type . ')',
                ['element_type' => $sectorial->element_type]
            );

            return response()->json([
                'message' => 'Elemento creado correctamente.',
                'sectorial' => $sectorial
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => 'Ya existe un elemento con ese nombre en tu red.',
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear el elemento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $sectorial = Sectorial::with([
                'photos.user:id,user_name,user_lastname',
                'notes.user:id,user_name,user_lastname',
            ])->findOrFail($id);

            return response()->json($sectorial);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Elemento no encontrado',
                'error' => 'No existe un elemento con el ID: ' . $id
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cargar el elemento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $sectorial = Sectorial::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'element_type' => 'nullable|in:sectorial,switch,nodo',
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
            $original = $sectorial->getAttributes();
            $sectorial->update($data);

            $changedFields = collect($data)
                ->keys()
                ->filter(fn($k) => array_key_exists($k, $original) && $original[$k] !== $sectorial->getAttribute($k))
                ->values()
                ->all();

            if (!empty($changedFields)) {
                SectorialHistory::log(
                    $sectorial->id,
                    'updated',
                    'Se actualizó el elemento "' . $sectorial->name . '"',
                    ['fields' => $changedFields]
                );
            }

            return response()->json([
                'message' => 'Elemento actualizado correctamente.',
                'sectorial' => $sectorial
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar el elemento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $sectorial = Sectorial::findOrFail($id);
            $sectorial->delete();

            return response()->json([
                'message' => 'Elemento eliminado correctamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el elemento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
