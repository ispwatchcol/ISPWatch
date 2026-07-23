<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

/**
 * CRUD for expense categories. Tenant scoping is automatic via BelongsToTenant.
 */
class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return response()->json(ExpenseCategory::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        return response()->json(ExpenseCategory::create($data), 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $expenseCategory->update($data);

        return response()->json($expenseCategory);
    }

    public function destroy(ExpenseCategory $expenseCategory)
    {
        $expenseCategory->delete();

        return response()->json(['message' => 'Categoría eliminada correctamente.']);
    }
}
