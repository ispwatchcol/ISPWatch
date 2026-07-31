<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de tipos de factura (Plan Mensual, Instalación, Equipos, TV...).
 *
 * Los cuatro tipos del sistema (tenant_id NULL) los ve todo el mundo y nadie
 * los edita: la facturación automática, la instalación y los cargos de ticket
 * dependen de esos slugs. Encima de ellos cada tenant crea los suyos.
 */
class InvoiceTypeController extends Controller
{
    private function tenantId(Request $request): ?int
    {
        $tenantId = $request->user()?->tenant_id;

        return $tenantId ? (int) $tenantId : null;
    }

    public function index(Request $request)
    {
        $types = InvoiceType::query()
            ->forTenant($this->tenantId($request))
            ->ordered()
            ->get();

        return response()->json($types);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);

        // Sin tenant, la fila nacería con tenant_id NULL — y eso, en este
        // catálogo, significa "tipo del sistema visible para TODOS los tenants".
        if (!$tenantId) {
            return response()->json([
                'message' => 'Tu usuario no pertenece a ninguna empresa, no puede crear tipos de factura.',
            ], 403);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'color'       => ['nullable', 'string', Rule::in(InvoiceType::COLORS)],
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        $slug = InvoiceType::slugFromName($data['name']);

        if ($slug === '') {
            return response()->json(['message' => 'El nombre debe tener al menos una letra o número.'], 422);
        }

        // Colisión contra los tipos del sistema o contra otro tipo del tenant:
        // el slug es lo que se guarda en invoices.invoice_type, así que dos
        // tipos con el mismo slug serían el mismo tipo.
        if (InvoiceType::query()->forTenant($tenantId)->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'Ya existe un tipo de factura con ese nombre.'], 422);
        }

        $type = InvoiceType::create([
            'tenant_id'   => $tenantId,
            'slug'        => $slug,
            'name'        => $data['name'],
            'color'       => $data['color'] ?? 'slate',
            'description' => $data['description'] ?? null,
            'is_system'   => false,
            'is_active'   => $data['is_active'] ?? true,
            'sort_order'  => 100,
        ]);

        return response()->json($type, 201);
    }

    public function update(Request $request, int $id)
    {
        $type = $this->findOwnType($request, $id);

        if (!$type) {
            return response()->json([
                'message' => 'Los tipos de factura del sistema no se pueden modificar.',
            ], 403);
        }

        $data = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'color'       => ['nullable', 'string', Rule::in(InvoiceType::COLORS)],
            'description' => 'nullable|string|max:255',
            'is_active'   => 'boolean',
        ]);

        // El slug NO se renombra: las facturas ya emitidas lo llevan grabado y
        // cambiarlo las dejaría huérfanas. Sólo cambia la etiqueta visible.
        $type->update($data);

        return response()->json($type->fresh());
    }

    public function destroy(Request $request, int $id)
    {
        $type = $this->findOwnType($request, $id);

        if (!$type) {
            return response()->json([
                'message' => 'Los tipos de factura del sistema no se pueden eliminar.',
            ], 403);
        }

        $used = Invoice::where('tenant_id', $type->tenant_id)
            ->where('invoice_type', $type->slug)
            ->count();

        if ($used > 0) {
            return response()->json([
                'message' => "Este tipo ya está usado en {$used} factura(s). Desactívalo en lugar de eliminarlo "
                    . 'para que las facturas existentes conserven su etiqueta.',
            ], 422);
        }

        $type->delete();

        return response()->json(['message' => 'Tipo de factura eliminado.']);
    }

    /** Tipo propio del tenant (nunca uno del sistema ni de otro tenant). */
    private function findOwnType(Request $request, int $id): ?InvoiceType
    {
        $tenantId = $this->tenantId($request);

        if (!$tenantId) {
            return null;
        }

        return InvoiceType::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('is_system', false)
            ->first();
    }
}
