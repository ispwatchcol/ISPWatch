<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use Illuminate\Http\Request;

/**
 * Lectura de la bitácora de auditoría y del extracto de saldo a favor.
 *
 * Solo lectura a propósito: una bitácora que se puede editar o borrar desde la
 * aplicación no sirve como bitácora. La retención se maneja por fuera.
 */
class AuditLogController extends Controller
{
    /** Modelos que el visor sabe nombrar en español. */
    protected const MODEL_LABELS = [
        'App\\Models\\Plan'            => 'Plan',
        'App\\Models\\CustomerProfile' => 'Cliente',
        'App\\Models\\Payment'         => 'Pago',
        'App\\Models\\Invoice'         => 'Factura',
        'App\\Models\\Billing'         => 'Configuración de facturación',
    ];

    public function index(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $query = AuditLog::query()
            ->with('user:id,name,user_name,user_lastname')
            ->forTenant($tenantId);

        if ($request->filled('model_type')) {
            // El front manda el nombre corto ("Plan"), no la clase completa.
            $query->where('model_type', 'App\\Models\\' . $request->string('model_type'));
        }

        if ($request->filled('model_id')) {
            $query->where('model_id', $request->integer('model_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        if ($request->filled('search')) {
            $query->whereLike('description', '%' . $request->string('search') . '%');
        }

        return response()->json(
            $query->orderByDesc('id')->paginate($request->integer('per_page') ?: 30)
        );
    }

    /**
     * Catálogo para poblar los filtros del visor sin quemar la lista en el front.
     */
    public function filters(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $actions = AuditLog::query()
            ->forTenant($tenantId)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json([
            'actions' => $actions,
            'models'  => collect(self::MODEL_LABELS)
                ->map(fn ($label, $class) => ['value' => class_basename($class), 'label' => $label])
                ->values(),
            'sources' => ['web', 'api', 'console', 'import', 'scheduler'],
        ]);
    }

    /**
     * Extracto del saldo a favor de un cliente.
     *
     * Devuelve además el contraste entre el saldo del libro y el escalar
     * cacheado en customer_profile: si no coinciden hay un bug, y es preferible
     * que se vea en pantalla a que se descubra en el mostrador.
     */
    public function creditMovements(Request $request, int $customerId)
    {
        $tenantId = $request->user()?->tenant_id;

        $profile = CustomerProfile::where('user_id', $customerId)->firstOrFail();

        $movements = CustomerCredit::query()
            ->where('customer_id', $customerId)
            ->with([
                'invoice:id,number,total,issue_date',
                'payment:id,amount,payment_date,reference',
                'createdBy:id,name,user_name,user_lastname',
            ])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page') ?: 25);

        $ledger = CustomerCredit::ledgerBalanceFor($customerId);
        $cached = round((float) $profile->credit_balance, 2);

        return response()->json([
            'movements'      => $movements,
            'ledger_balance' => $ledger,
            'cached_balance' => $cached,
            // Distinto de cero = el libro y la caché divergieron.
            'discrepancy'    => round($ledger - $cached, 2),
            'tenant_id'      => $tenantId,
        ]);
    }
}
