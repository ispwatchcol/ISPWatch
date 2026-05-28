<?php

namespace App\Http\Controllers;

use App\Models\CustomerDocument;
use App\Models\Prospect;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProspectController extends Controller
{
    private function authTenant(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');
        return $tenantId;
    }

    private function resolve(Request $request, $id): Prospect
    {
        return Prospect::where('tenant_id', $this->authTenant($request))->findOrFail($id);
    }

    public function index(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $rows = Prospect::where('tenant_id', $tenantId)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->q, function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'ilike', $like)
                      ->orWhere('last_name', 'ilike', $like)
                      ->orWhere('cedula', 'ilike', $like)
                      ->orWhere('email', 'ilike', $like)
                      ->orWhere('tel', 'ilike', $like);
                });
            })
            ->withCount('installations')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rows);
    }

    public function show(Request $request, $id)
    {
        $prospect = $this->resolve($request, $id);
        $prospect->load(['installations' => fn($q) => $q->orderByDesc('scheduled_date')]);
        return response()->json($prospect);
    }

    public function store(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'cedula'    => 'nullable|string|max:40',
            'email'     => 'nullable|email|max:180',
            'tel'       => 'nullable|string|max:40',
            'address'   => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:120',
            'state'     => 'nullable|string|max:120',
            'estrato'   => 'nullable|integer|between:1,6',
            'notes'     => 'nullable|string|max:2000',
            'status'    => 'nullable|in:' . implode(',', Prospect::STATUSES),
        ]);

        $prospect = Prospect::create(array_merge($data, [
            'tenant_id'  => $tenantId,
            'status'     => $data['status'] ?? 'interesado',
            'created_by' => $request->user()?->id,
        ]));

        return response()->json([
            'message'  => 'Prospecto creado correctamente.',
            'prospect' => $prospect,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $prospect = $this->resolve($request, $id);

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'cedula'    => 'nullable|string|max:40',
            'email'     => 'nullable|email|max:180',
            'tel'       => 'nullable|string|max:40',
            'address'   => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:120',
            'state'     => 'nullable|string|max:120',
            'estrato'   => 'nullable|integer|between:1,6',
            'notes'     => 'nullable|string|max:2000',
            'status'    => 'nullable|in:' . implode(',', Prospect::STATUSES),
        ]);

        $prospect->update($data);

        return response()->json([
            'message'  => 'Prospecto actualizado.',
            'prospect' => $prospect->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $prospect = $this->resolve($request, $id);

        abort_if(
            $prospect->status === 'convertido',
            422,
            'No se puede eliminar un prospecto ya convertido en cliente.'
        );

        $prospect->delete();

        return response()->json(['message' => 'Prospecto eliminado.']);
    }

    /**
     * Mark a prospect as converted to a real client. Called by the customer-add
     * flow after a successful client creation when a prospect_id was passed.
     */
    public function markConverted(Request $request, $id)
    {
        $prospect = $this->resolve($request, $id);
        $tenantId = $this->authTenant($request);

        $data = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $userBelongs = User::where('id', $data['user_id'])
            ->where('tenant_id', $tenantId)
            ->exists();
        abort_if(!$userBelongs, 422, 'El usuario indicado no pertenece al tenant.');

        DB::transaction(function () use ($prospect, $data) {
            $prospect->update([
                'status'            => 'convertido',
                'converted_user_id' => $data['user_id'],
                'converted_at'      => now(),
            ]);

            // Carry the new customer_id onto any pending/completed installations
            // that still point only to this prospect — preserves history.
            $installationIds = $prospect->installations()->whereNull('customer_id')->pluck('id');

            $prospect->installations()->whereNull('customer_id')->update([
                'customer_id' => $data['user_id'],
            ]);

            // Re-home any documents (photos / signed PDF) attached to those
            // installations so they show up under the new customer's profile.
            if ($installationIds->isNotEmpty()) {
                CustomerDocument::whereIn('installation_id', $installationIds)
                    ->whereNull('customer_id')
                    ->update(['customer_id' => $data['user_id']]);
            }
        });

        return response()->json([
            'message'  => 'Prospecto convertido en cliente.',
            'prospect' => $prospect->fresh(),
        ]);
    }
}
