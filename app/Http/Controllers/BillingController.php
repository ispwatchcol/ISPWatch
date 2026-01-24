<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
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
        $query = Invoice::query()->with(['customer.customerProfile']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%$search%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('user_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhereHas('customerProfile', function ($cpq) use ($search) {
                                $cpq->where('name', 'like', "%$search%")
                                    ->orWhere('last_name', 'like', "%$search%");
                            });
                    });
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('period')) {
            try {
                $period = \Illuminate\Support\Carbon::parse($request->period)->format('Y-m');
                $query->where('period_start', 'like', $period . '%');
            } catch (\Exception $e) {
                $query->where('period_start', 'like', $request->period . '%');
            }
        }
        if ($request->has('tenant_id') || $request->has('tenant')) {
            $tenantId = $request->tenant_id ?? $request->tenant;
            $query->where('tenant_id', $tenantId);
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
        $data = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'tenant_id' => 'required',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $data['status'] = 'issued';
        $data['subtotal'] = 0;
        $data['total'] = 0;
        $data['balance_due'] = 0;
        $data['currency'] = 'COP';

        // Generate invoice number using BillingService
        $data['number'] = $this->billingService->generateInvoiceNumber($data['tenant_id']);

        $invoice = Invoice::create($data);
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

    // List Payments
    public function getPayments(Request $request)
    {
        $query = Payment::query()->with(['customer.customerProfile', 'allocations.invoice']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%$search%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('user_name', 'like', "%$search%")
                            ->orWhere('email', 'like', "%$search%")
                            ->orWhereHas('customerProfile', function ($cpq) use ($search) {
                                $cpq->where('name', 'like', "%$search%")
                                    ->orWhere('last_name', 'like', "%$search%");
                            });
                    });
            });
        }

        if ($request->filled('method')) {
            $query->where('method', $request->input('method'));
        }

        if ($request->has('tenant_id') || $request->has('tenant')) {
            $tenantId = $request->tenant_id ?? $request->tenant;
            $query->where('tenant_id', $tenantId);
        }

        return response()->json($query->orderBy('payment_date', 'desc')->paginate(10));
    }

    // Dashboard Stats
    public function getStats(Request $request)
    {
        $tenantId = $request->tenant_id ?? $request->tenant;

        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID required'], 400);
        }

        $totalInvoiced = Invoice::where('tenant_id', $tenantId)->sum('total');
        $totalPaid = Payment::where('tenant_id', $tenantId)->sum('amount');
        $totalPending = Invoice::where('tenant_id', $tenantId)->sum('balance_due');

        // Recent activity
        $recentInvoices = Invoice::where('tenant_id', $tenantId)->with('customer.customerProfile')->orderBy('created_at', 'desc')->limit(5)->get();
        $recentPayments = Payment::where('tenant_id', $tenantId)->with('customer.customerProfile')->orderBy('created_at', 'desc')->limit(5)->get();

        return response()->json([
            'summary' => [
                'total_invoiced' => (float) $totalInvoiced,
                'total_paid' => (float) $totalPaid,
                'total_pending' => (float) $totalPending,
                'collection_rate' => $totalInvoiced > 0 ? round(($totalPaid / $totalInvoiced) * 100, 2) : 0
            ],
            'recent_invoices' => $recentInvoices,
            'recent_payments' => $recentPayments,
            'currency' => '$'
        ]);
    }

    // Process Overdue Invoices (Admin)
    public function processOverdue(Request $request)
    {
        $suspensionService = app(\App\Services\OverdueSuspensionService::class);
        $stats = $suspensionService->processOverdueInvoices();

        return response()->json([
            'message' => 'Overdue processing complete',
            'stats' => $stats
        ]);
    }
}
