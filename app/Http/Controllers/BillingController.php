<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OverdueSuspensionService;
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
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->search);
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
        if ($request->filled('invoice_type')) {
            $query->where('invoice_type', $request->invoice_type);
        }
        if ($request->filled('period')) {
            try {
                $period = \Illuminate\Support\Carbon::parse($request->period)->format('Y-m');
                $query->where('period_start', 'like', $period . '%');
            } catch (\Exception $e) {
                $query->where('period_start', 'like', $request->period . '%');
            }
        }

        // SECURITY FIX (OWASP A01): Always filter by authenticated user's tenant.
        // Never accept tenant_id from query params — that allows cross-tenant invoice browsing.
        $tenantId = $request->user()?->tenant_id;
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return response()->json($query->orderBy('issue_date', 'desc')->paginate(20));
    }

    // Show Invoice
    public function show($id)
    {
        return response()->json(Invoice::with(['customer', 'items', 'payments', 'ticket'])->findOrFail($id));
    }

    // Manual Create (Draft)
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'tenant_id'   => 'required',
            'issue_date'  => 'required|date',
            'due_date'    => 'required|date',
            'period_start'=> 'required|date',
            'period_end'  => 'required|date',
            'total'       => 'nullable|numeric|min:0',
            'notes'       => 'nullable|string',
        ]);

        $total = $data['total'] ?? 0;
        $data['status']      = 'issued';
        $data['subtotal']    = $total;
        $data['total']       = $total;
        $data['balance_due'] = $total;
        $data['currency']    = 'COP';

        // Generate invoice number using BillingService
        $data['number'] = $this->billingService->generateInvoiceNumber($data['tenant_id']);

        $invoice = Invoice::create($data);
        return response()->json($invoice, 201);
    }

    // Update Invoice
    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'status'       => 'sometimes|in:issued,pending,paid,overdue,cancelled',
            'issue_date'   => 'sometimes|date',
            'due_date'     => 'sometimes|date',
            'period_start' => 'sometimes|nullable|date',
            'period_end'   => 'sometimes|nullable|date',
            'total'        => 'sometimes|numeric|min:0',
            'balance_due'  => 'sometimes|numeric|min:0',
            'notes'        => 'nullable|string',
        ]);

        $invoice->update($data);
        return response()->json($invoice->fresh(['customer', 'items', 'payments']));
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

    // Update Payment
    public function updatePayment(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        $data = $request->validate([
            'amount'       => 'sometimes|numeric|min:0.01',
            'payment_date' => 'sometimes|date',
            'method'       => 'sometimes|string',
            'reference'    => 'nullable|string|max:255',
            'notes'        => 'nullable|string',
        ]);

        $payment = $this->billingService->updatePayment($payment, $data);

        return response()->json($payment->load('allocations'));
    }

    // Delete Payment
    public function deletePayment($id)
    {
        $payment = Payment::findOrFail($id);
        $this->billingService->deletePayment($payment);
        return response()->json(['message' => 'Pago eliminado correctamente.']);
    }

    // Create standalone additional charge (not linked to a ticket)
    public function storeAdditionalCharge(Request $request)
    {
        $data = $request->validate([
            'customer_id'         => 'required|exists:users,id',
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.type'        => 'nullable|string|max:50',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',
        ]);

        $tenantId = $request->user()?->tenant_id;

        try {
            $invoice = $this->billingService->generateServiceChargeInvoice([
                'tenant_id'    => $tenantId,
                'customer_id'  => $data['customer_id'],
                'invoice_type' => Invoice::TYPE_ADDITIONAL,
                'items'        => $data['items'],
                'due_date'     => $data['due_date'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'Cargo adicional generado correctamente. ✅',
                'invoice' => $invoice,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error generating additional charge: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al generar el cargo adicional.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // PDF Download
    public function downloadPdf($id)
    {
        $invoice = Invoice::with(['customer.customerProfile', 'items', 'tenant', 'ticket'])->findOrFail($id);

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
        $balance       = (float) Invoice::where('customer_id', $customerId)->sum('balance_due');
        $customer      = \App\Models\CustomerProfile::where('user_id', $customerId)->first();
        $creditBalance = $customer ? (float) $customer->credit_balance : 0;
        $netBalance    = max(0, $balance - $creditBalance);

        return response()->json([
            'balance'        => $balance,
            'credit_balance' => $creditBalance,
            'net_balance'    => $netBalance,
        ]);
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

    // Process Overdue Invoices (Admin) — legacy endpoint
    public function processOverdue(Request $request)
    {
        $suspensionService = app(OverdueSuspensionService::class);
        $stats = $suspensionService->processOverdueInvoices();

        return response()->json([
            'message' => 'Overdue processing complete',
            'stats' => $stats,
        ]);
    }

    // ─── Billing Configs ─────────────────────────────────────────────────────

    // List all billing configs with their associated routers
    public function getBillingConfigs()
    {
        $configs = Billing::with('routers:id,name,cut_type_id,billing_router_id')
            ->with('routers.cutType:id,name')
            ->get();

        return response()->json($configs);
    }

    // Update a billing config (cut_day, cut_time, overdue_invoices, etc.)
    public function updateBillingConfig(Request $request, $id)
    {
        $billing = Billing::findOrFail($id);

        $validated = $request->validate([
            'create_invoice' => 'nullable|date',
            'payment_day' => 'nullable|date',
            'payment_reminder' => 'nullable|date',
            'payment_reminder_enabled' => 'nullable|boolean',
            'cut_day' => 'nullable|date',
            'cut_time' => 'nullable|date_format:H:i,H:i:s',
            'overdue_invoices' => 'nullable|integer|min:1',
            'billing_mode' => 'nullable|in:anticipado,vencido',
            'notification_type' => 'nullable|in:email,whatsapp,both,none',
            'notificar_wpp' => 'nullable|boolean',
            'comments' => 'nullable|string',
        ]);

        $billing->update($validated);

        return response()->json($billing->fresh()->load('routers'));
    }

    // Trigger auto-cut manually (optionally scoped to one router)
    public function runAutoCut(Request $request)
    {
        $request->validate([
            'router_id' => 'nullable|integer|exists:router,id',
        ]);

        $suspensionService = app(OverdueSuspensionService::class);
        $stats = $suspensionService->processOverdueInvoices($request->input('router_id'));

        return response()->json([
            'message' => 'Corte automático ejecutado',
            'stats' => $stats,
        ]);
    }
}
