<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Traits\ExportsCsv;
use Illuminate\Http\Request;

/**
 * CRUD for company expenses. Tenant scoping is automatic via BelongsToTenant.
 * No hard delete: an expense can only be voided (status = anulado) via update.
 */
class ExpenseController extends Controller
{
    use ExportsCsv;

    /**
     * Consulta de gastos con los filtros del listado aplicados, SIN orden ni
     * paginación.
     *
     * Compartida por el listado y la exportación: si cada uno armara sus
     * propios filtros acabarían divergiendo, y el CSV dejaría de corresponder a
     * lo que el usuario tiene en pantalla — que es justo lo que promete.
     */
    private function filteredExpensesQuery(Request $request)
    {
        $query = Expense::with(['category', 'beneficiary', 'creator']);

        // Búsqueda de texto: sin esto, encontrar un gasto puntual del que no se
        // recuerda la fecha exacta obligaba a recorrer la lista a ojo. Cubre lo
        // que la tabla muestra como texto libre — descripción, observaciones y
        // la persona a nombre de quién quedó.
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->whereLike('description', $search)
                    ->orWhereLike('notes', $search)
                    ->orWhereHas('beneficiary', function ($uq) use ($search) {
                        $uq->where(function ($iq) use ($search) {
                            $iq->whereLike('name', $search)
                                ->orWhereLike('user_name', $search)
                                ->orWhereLike('user_lastname', $search);
                        });
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->query('date_to'));
        }

        if ($request->filled('expense_category_id')) {
            $query->where('expense_category_id', $request->query('expense_category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->filteredExpensesQuery($request);

        // Agregados del resumen: se calculan en SQL sobre el filtro COMPLETO,
        // nunca sobre la página. Antes los sumaba la vista recorriendo el array
        // entero, que funcionaba sólo porque el endpoint devolvía todo sin
        // paginar; al paginar, ese mismo cálculo pasaría a mostrar el total de
        // la página visible bajo el rótulo "total del período filtrado" — un
        // importe incorrecto con la misma apariencia de correcto.
        //
        // Los anulados quedan fuera del resumen aunque el filtro de estado no
        // los excluya: es la regla que ya aplicaba la vista (`activeItems`) y la
        // que hace que el total represente dinero realmente gastado.
        $summaryQuery = (clone $query)->where('status', '!=', Expense::STATUS_VOID);

        // Se acota por arriba en vez de rechazar: un `per_page` absurdo es un
        // error de quien llama, no algo que deba tumbar el listado.
        $perPage = max(1, min((int) $request->query('per_page', 15), 200));

        // `expense_date` es una fecha sin hora y se repite mucho: sin desempate
        // estable, dos páginas pueden repetir u omitir el mismo gasto.
        $paginator = $query->orderByDesc('expense_date')->orderByDesc('id')->paginate($perPage);

        return response()->json($paginator->toArray() + [
            'summary' => [
                'total'       => (float) (clone $summaryQuery)->sum('amount'),
                'count'       => (clone $summaryQuery)->count(),
                'by_category' => $this->categoryBreakdown(clone $summaryQuery),
            ],
        ]);
    }

    /**
     * Total por categoría del filtro completo, ordenado de mayor a menor.
     *
     * Va por `toBase()` para que no se hidraten modelos ni corran los eager
     * loads del listado: es una agregación, no una lista de gastos. El
     * "Sin categoría" se resuelve en PHP y no con COALESCE en SQL para no
     * depender de cómo agrupa cada motor una columna nula.
     */
    private function categoryBreakdown($query): array
    {
        return $query->toBase()
            ->leftJoin('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->selectRaw('expense_categories.name as name, SUM(expenses.amount) as total')
            ->groupBy('expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name'  => $row->name ?? 'Sin categoría',
                'total' => (float) $row->total,
            ])
            ->all();
    }

    /**
     * Exporta a CSV los gastos del filtro aplicado — todos, no la página.
     *
     * Incluye los anulados (con su estado en una columna) porque el archivo es
     * el registro completo de lo que pasó: quitarlos escondería justamente las
     * correcciones. Quien quiera sólo los vigentes filtra por estado antes de
     * exportar, y el CSV respeta ese filtro.
     */
    public function exportExpenses(Request $request)
    {
        $query = $this->filteredExpensesQuery($request)
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        $columns = ['Fecha', 'Categoría', 'Descripción', 'A nombre de', 'Monto', 'Estado', 'Observaciones'];

        return $this->streamCsv(
            'gastos-' . now()->format('Y-m-d') . '.csv',
            $columns,
            $query,
            fn (Expense $expense) => [
                $this->csvDate($expense->expense_date),
                $expense->category?->name ?? 'Sin categoría',
                $expense->description ?? '',
                $expense->beneficiary?->name ?? '',
                $this->csvMoney($expense->amount),
                $expense->status,
                $expense->notes ?? '',
            ]
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'user_id' => 'nullable|exists:users,id',
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $data['created_by'] = $request->user()->id;

        $expense = Expense::create($data);

        return response()->json($expense->load(['category', 'beneficiary', 'creator']), 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'user_id' => 'nullable|exists:users,id',
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => 'required|in:' . Expense::STATUS_ACTIVE . ',' . Expense::STATUS_VOID,
        ]);

        $expense->update($data);

        return response()->json($expense->load(['category', 'beneficiary', 'creator']));
    }
}
