<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;

/**
 * CRUD for company expenses. Tenant scoping is automatic via BelongsToTenant.
 * No hard delete: an expense can only be voided (status = anulado) via update.
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with(['category', 'beneficiary', 'creator']);

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

        return response()->json($query->orderByDesc('expense_date')->get());
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
