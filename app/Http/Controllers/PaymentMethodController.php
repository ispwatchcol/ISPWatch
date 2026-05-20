<?php
namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    public function index()
    {
        $tenantId = $this->tenantId();

        // Auto-seed defaults the first time a tenant accesses payment methods.
        if (PaymentMethod::where('tenant_id', $tenantId)->count() === 0) {
            $now = now();
            $rows = array_map(fn($d) => array_merge($d, [
                'tenant_id'  => $tenantId,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]), PaymentMethod::$defaults);

            PaymentMethod::insert($rows);
        }

        $methods = PaymentMethod::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json($methods);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        $tenantId = $this->tenantId();

        if (PaymentMethod::where('tenant_id', $tenantId)->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->exists()) {
            return response()->json(['message' => 'Ya existe una forma de pago con ese nombre.'], 422);
        }

        $method = PaymentMethod::create([
            'tenant_id'   => $tenantId,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
        ]);

        return response()->json($method, 201);
    }

    public function update(Request $request, int $id)
    {
        $tenantId = $this->tenantId();
        $method = PaymentMethod::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        if (!empty($data['name']) && strtolower($data['name']) !== strtolower($method->name)) {
            if (PaymentMethod::where('tenant_id', $tenantId)
                ->where('id', '!=', $id)
                ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->exists()) {
                return response()->json(['message' => 'Ya existe una forma de pago con ese nombre.'], 422);
            }
        }

        $method->update($data);
        return response()->json($method);
    }

    public function destroy(int $id)
    {
        $tenantId = $this->tenantId();
        $method = PaymentMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $method->delete();
        return response()->json(['message' => 'Forma de pago eliminada.']);
    }
}
