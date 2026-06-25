<?php

namespace App\Http\Controllers;

use App\Models\CustomerProfile;
use App\Models\Sectorial;
use App\Models\SectorialHistory;
use App\Traits\FixesSequences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SectorialController extends Controller
{
    use FixesSequences;

    /**
     * Reglas de validación compartidas por store/update.
     * El parámetro $required marca si `name` es obligatorio (alta).
     */
    private function rules(bool $required = true): array
    {
        return [
            'name'                   => ($required ? 'required' : 'sometimes|required') . '|string|max:255',
            'element_type'           => 'nullable|in:' . implode(',', Sectorial::ELEMENT_TYPES),
            'parent_id'              => 'nullable|integer|exists:sectorial,id',
            'ip'                     => 'nullable|string|max:255',
            'type'                   => 'nullable|string|max:255',
            'split_ratio'            => 'nullable|string|max:10',
            'ports_total'            => 'nullable|integer|min:0|max:1024',
            'pon_port'               => 'nullable|string|max:50',
            'vlan'                   => 'nullable|integer|min:0|max:4096',
            'user_rb'                => 'nullable|string|max:255',
            'pass_rb'                => 'nullable|string|max:255',
            'zona_id'                => 'nullable|integer',
            'frequency'              => 'nullable|integer',
            'node_tower'             => 'nullable|string|max:255',
            'comments'               => 'nullable|string',
            'ssid'                   => 'nullable|string',
            'coordinates'            => 'nullable|json',
            'coverage_radius_meters' => 'nullable|integer|min:0|max:100000',
            'antenna_type'           => 'nullable|string|max:100',
        ];
    }

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

        $this->attachPortUsage($sectorials, $tenantId);

        return response()->json($sectorials);
    }

    /**
     * Inyecta children_count y clients_count en cada elemento con solo 2
     * consultas agrupadas (evita N+1 al serializar ports_used/ports_free).
     */
    private function attachPortUsage($sectorials, $tenantId): void
    {
        $ids = $sectorials->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $childCounts = Sectorial::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('parent_id', $ids)
            ->groupBy('parent_id')
            ->selectRaw('parent_id, count(*) as c')
            ->pluck('c', 'parent_id');

        // customer_profile NO tiene tenant_id (se acota vía users). Como $ids ya
        // son sectoriales del tenant, filtrar por sectorial_id es suficiente.
        $clientCounts = CustomerProfile::query()
            ->whereIn('sectorial_id', $ids)
            ->groupBy('sectorial_id')
            ->selectRaw('sectorial_id, count(*) as c')
            ->pluck('c', 'sectorial_id');

        foreach ($sectorials as $s) {
            $s->children_count = (int) ($childCounts[$s->id] ?? 0);
            $s->clients_count  = (int) ($clientCounts[$s->id] ?? 0);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));

        $data['element_type'] = $data['element_type'] ?? Sectorial::ELEMENT_SECTORIAL;

        try {
            $sectorial = $this->createWithSequenceFix(Sectorial::class, $data);

            try {
                SectorialHistory::log(
                    $sectorial->id,
                    'created',
                    'Se creó el elemento "' . $sectorial->name . '" (' . $sectorial->element_type . ')',
                    ['element_type' => $sectorial->element_type]
                );
            } catch (\Exception $historyEx) {
                \Log::warning('SectorialHistory::log failed (table may be missing in this schema)', [
                    'error' => $historyEx->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Elemento creado correctamente.',
                'sectorial' => $sectorial
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => 'Ya existe un elemento con ese nombre en tu red.',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('SectorialController@store failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'data'  => $data,
            ]);
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
                'parent:id,name,element_type',
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

        $data = $request->validate($this->rules(false));

        // Un elemento no puede colgar de sí mismo.
        if (array_key_exists('parent_id', $data) && (int) $data['parent_id'] === (int) $id) {
            return response()->json([
                'message' => 'Un elemento no puede ser su propio padre.',
            ], 422);
        }

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
