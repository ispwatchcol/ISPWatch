<?php

namespace App\Http\Controllers;

use App\Models\CustomerInstallation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CustomerInstallationController extends Controller
{
    private function resolveCustomer(Request $request, $customerId): User
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return User::where('tenant_id', $tenantId)->findOrFail($customerId);
    }

    public function all(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $installations = CustomerInstallation::with(['customer.profile'])
            ->where('tenant_id', $tenantId)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->from,   fn($q, $d) => $q->whereDate('scheduled_date', '>=', $d))
            ->when($request->to,     fn($q, $d) => $q->whereDate('scheduled_date', '<=', $d))
            ->orderBy('scheduled_date', 'desc')
            ->get()
            ->map(function ($inst) {
                $profile = $inst->customer?->profile;
                return array_merge($inst->toArray(), [
                    'customer_name' => trim(($profile?->name ?? '') . ' ' . ($profile?->last_name ?? ''))
                        ?: $inst->customer?->email,
                    'customer_email' => $inst->customer?->email,
                ]);
            });

        return response()->json($installations);
    }

    public function index(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $installations = CustomerInstallation::where('customer_id', $customer->id)
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json($installations);
    }

    public function store(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $data = $request->validate([
            'scheduled_date' => 'required|date',
            'technician'     => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'in:pendiente,completada,cancelada',
        ]);

        $installation = CustomerInstallation::create([
            'tenant_id'    => $customer->tenant_id,
            'customer_id'  => $customer->id,
            'scheduled_date' => $data['scheduled_date'],
            'technician'   => $data['technician'] ?? null,
            'address'      => $data['address'] ?? null,
            'equipment'    => $data['equipment'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'status'       => $data['status'] ?? 'pendiente',
            'completed_at' => ($data['status'] ?? 'pendiente') === 'completada' ? now() : null,
            'created_by'   => $request->user()?->id,
        ]);

        return response()->json([
            'message'      => 'Orden de instalación creada correctamente.',
            'installation' => $installation,
        ], 201);
    }

    public function update(Request $request, $installationId)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $installation = CustomerInstallation::where('tenant_id', $tenantId)->findOrFail($installationId);

        $data = $request->validate([
            'scheduled_date' => 'required|date',
            'technician'     => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'in:pendiente,completada,cancelada',
        ]);

        if (isset($data['status']) && $data['status'] === 'completada' && $installation->status !== 'completada') {
            $data['completed_at'] = now();
        } elseif (isset($data['status']) && $data['status'] !== 'completada') {
            $data['completed_at'] = null;
        }

        $installation->update($data);

        return response()->json([
            'message'      => 'Orden de instalación actualizada correctamente.',
            'installation' => $installation->fresh(),
        ]);
    }

    public function destroy(Request $request, $installationId)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $installation = CustomerInstallation::where('tenant_id', $tenantId)->findOrFail($installationId);
        $installation->delete();

        return response()->json(['message' => 'Orden de instalación eliminada correctamente.']);
    }
}
