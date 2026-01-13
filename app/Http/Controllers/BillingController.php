<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    protected $billingService;

    public function __construct(BillingService $billingService)
    {
        $this->billingService = $billingService;
    }

    // List Invoices
    public function index(Request $request)
    {
        $query = Invoice::query()->with('customer');

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('period')) {
            $query->where('period_start', 'like', $request->period . '%');
        }
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        } elseif ($request->has('tenant')) {
            $query->where('tenant_id', $request->tenant);
        }

        return response()->json($query->orderBy('issue_date', 'desc')->paginate(20));
    }

    // Show Invoice
    public function show($id)
    {
        return response()->json(Invoice::with(['customer', 'items', 'payments'])->findOrFail($id));
    }

    // Manual Create (Draft)
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:users,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
        ]);

        $invoice = Invoice::create($request->all());
        return response()->json($invoice, 201);
    }

    // Add Items
    public function addItems(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);
        $request->validate([
            'description' => 'required',
            'amount' => 'required|numeric',
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'type' => $request->type ?? 'adjustment',
            'description' => $request->description,
            'quantity' => $request->quantity ?? 1,
            'unit_price' => $request->unit_price ?? $request->amount,
            'amount' => $request->amount
        ]);

        // Recalculate totals
        $invoice->subtotal += $item->amount;
        $invoice->total = $invoice->subtotal;
        $invoice->balance_due += $item->amount;
        $invoice->save();

        return response()->json($invoice->load('items'));
    }

    // Register Payment
    public function registerPayment(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'method' => 'required',
        ]);

        $payment = $this->billingService->registerPayment($request->all());

        return response()->json($payment->load('allocations'), 201);
    }

    // PDF Download
    public function downloadPdf($id)
    {
        $invoice = Invoice::with(['customer', 'items', 'tenant'])->findOrFail($id);

        $pdf = Pdf::loadView('billing.invoice_pdf', compact('invoice'));
        return $pdf->download('Invoice-' . $invoice->number . '.pdf');
    }

    // Run Monthly Generation (Admin)
    public function runMonthlyGeneration(Request $request)
    {
        $period = $request->input('period'); // YYYY-MM
        $count = $this->billingService->generateMonthlyInvoices($period);
        return response()->json(['message' => "Generated $count invoices."]);
    }

    // Customer Balance
    public function getCustomerBalance($customerId)
    {
        $balance = Invoice::where('customer_id', $customerId)
            ->sum('balance_due');

        return response()->json(['balance' => $balance]);
    }
}
