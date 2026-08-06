<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OverdueSuspensionService;
use App\Services\Templates\TemplateRenderer;
use App\Traits\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    use ExportsCsv;

    protected $billingService;
    protected TemplateRenderer $templateRenderer;

    public function __construct(BillingService $billingService, TemplateRenderer $templateRenderer)
    {
        $this->billingService = $billingService;
        $this->templateRenderer = $templateRenderer;
    }

    /**
     * Consulta de facturas con los filtros del listado aplicados, SIN orden ni
     * paginación.
     *
     * Vive aparte porque la usan el listado y la exportación: si cada uno
     * armara sus propios filtros, acabarían divergiendo y el CSV dejaría de
     * corresponder a lo que el usuario tenía en pantalla — que es justo lo que
     * la exportación promete.
     */
    private function filteredInvoicesQuery(Request $request)
    {
        $query = Invoice::query()->with(['customer.customerProfile']);

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->search);
            // PostgreSQL's LIKE is case-sensitive, so "eliud" no coincide con
            // "Eliud". Usamos ILIKE en pgsql; SQLite (tests) no tiene ILIKE pero
            // su LIKE ya es insensible a mayúsculas para ASCII.
            $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($search, $likeOp) {
                $q->where('number', $likeOp, "%$search%")
                    ->orWhereHas('customer', function ($cq) use ($search, $likeOp) {
                        $cq->where('user_name', $likeOp, "%$search%")
                            ->orWhere('email', $likeOp, "%$search%")
                            ->orWhereHas('customerProfile', function ($cpq) use ($search, $likeOp) {
                                $cpq->where('name', $likeOp, "%$search%")
                                    ->orWhere('last_name', $likeOp, "%$search%");
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

        return $query;
    }

    // List Invoices
    public function index(Request $request)
    {
        $query = $this->filteredInvoicesQuery($request);

        // Agregados del listado, misma convención que Gastos: clave `summary` en
        // la MISMA respuesta, calculada en SQL sobre el filtro completo. En un
        // endpoint aparte, la cifra y la lista podrían responder a filtros
        // distintos sin que nada lo delatara.
        //
        // Las anuladas quedan fuera del dinero: una factura 'void'/'cancelled'
        // no se facturó. Es la regla equivalente a la de los gastos anulados.
        $summaryQuery = (clone $query)->whereNotIn('status', ['void', 'cancelled']);

        // `issue_date` es una fecha sin hora y se repite (toda la facturación
        // mensual comparte día): sin desempate estable, dos páginas pueden
        // repetir u omitir la misma factura.
        $paginator = $query->orderBy('issue_date', 'desc')->orderBy('id', 'desc')->paginate(20);

        return response()->json($paginator->toArray() + [
            'summary' => [
                'total'       => (float) (clone $summaryQuery)->sum('total'),
                'balance_due' => (float) (clone $summaryQuery)->sum('balance_due'),
                'count'       => (clone $summaryQuery)->count(),
            ],
        ]);
    }

    /**
     * Exporta a CSV las facturas del filtro aplicado — todas, no la página.
     *
     * Reutiliza `filteredInvoicesQuery()`, la misma consulta del listado: el
     * archivo no puede contener un conjunto distinto del que se ve en pantalla.
     */
    public function exportInvoices(Request $request)
    {
        $query = $this->filteredInvoicesQuery($request)
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        $columns = [
            'Número', 'Cliente', 'Correo', 'Tipo', 'Estado',
            'Emisión', 'Vencimiento', 'Período', 'Total', 'Saldo pendiente',
        ];

        return $this->streamCsv(
            'facturas-' . now()->format('Y-m-d') . '.csv',
            $columns,
            $query,
            function (Invoice $invoice) {
                $profile = $invoice->customer?->customerProfile;
                $nombre  = trim(($profile->name ?? '') . ' ' . ($profile->last_name ?? ''));

                return [
                    $invoice->number,
                    $nombre !== '' ? $nombre : ($invoice->customer?->user_name ?? ''),
                    $invoice->customer?->email ?? '',
                    $invoice->invoice_type,
                    $invoice->status,
                    $this->csvDate($invoice->issue_date),
                    $this->csvDate($invoice->due_date),
                    $this->csvDate($invoice->period_start),
                    $this->csvMoney($invoice->total),
                    $this->csvMoney($invoice->balance_due),
                ];
            }
        );
    }

    /**
     * Exporta a CSV los recaudos del filtro aplicado — todos, no la página.
     * Comparte `filteredPaymentsQuery()` con el listado por la misma razón.
     */
    public function exportPayments(Request $request)
    {
        $f = $this->validatedPaymentFilters($request);

        $query = $this->filteredPaymentsQuery($request, $f)
            ->orderBy($f['sort_by'] ?? 'payment_date', $f['sort_dir'] ?? 'desc')
            ->orderBy('id', 'desc');

        $columns = [
            'Fecha', 'Cliente', 'Monto', 'Método', 'Referencia',
            'Registrado por', 'Facturas afectadas',
        ];

        return $this->streamCsv(
            'recaudos-' . now()->format('Y-m-d') . '.csv',
            $columns,
            $query,
            function (Payment $payment) {
                $profile = $payment->customer?->customerProfile;
                $nombre  = trim(($profile->name ?? '') . ' ' . ($profile->last_name ?? ''));

                $creator = $payment->creator;
                $quien   = $creator
                    ? (trim(($creator->user_name ?? '') . ' ' . ($creator->user_lastname ?? '')) ?: ($creator->name ?? ''))
                    : 'sistema';

                // Misma información que la columna "Facturas afectadas" de la
                // tabla; sin asignaciones, el recaudo quedó como saldo a favor.
                $facturas = $payment->allocations
                    ->map(fn ($a) => $a->invoice?->number)
                    ->filter()
                    ->implode(', ');

                return [
                    $this->csvDate($payment->payment_date),
                    $nombre !== '' ? $nombre : ($payment->customer?->user_name ?? ''),
                    $this->csvMoney($payment->amount),
                    $payment->method,
                    $payment->reference ?? '',
                    $quien,
                    $facturas !== '' ? $facturas : 'Saldo a favor',
                ];
            }
        );
    }

    // Show Invoice
    public function show($id)
    {
        return response()->json(
            Invoice::with([
                'customer.customerProfile', 'items', 'payments', 'ticket',
                // Arrastre: de dónde vino el saldo que cobra esta factura y a
                // qué factura se fue el que ella dejó pendiente.
                'carryoversIn.fromInvoice:id,number',
                'carryoversOut.toInvoice:id,number',
            ])->findOrFail($id)
        );
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
            'invoice_type'=> ['nullable', 'string', 'max:50', $this->invoiceTypeRule($request)],
        ]);

        // Sin tipo explícito se mantiene el comportamiento histórico (la columna
        // nace con default 'monthly'), pero el formulario ya lo manda siempre.
        $data['invoice_type'] = $data['invoice_type'] ?? Invoice::TYPE_MONTHLY;

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

    // Mark an invoice as unpaid: reverse its payments and restore the balance.
    public function markUnpaid($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice = $this->billingService->markInvoiceUnpaid($invoice);

        return response()->json($invoice);
    }

    // Delete an invoice (reverses its payments to credit, removes items).
    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);

        // Auditoría ANTES de borrar: después la fila ya no existe y se perdería
        // el importe, el periodo y el cliente. Borrar una factura además deja
        // una lápida `suppressed` que impide regenerarla, así que es la acción
        // menos reversible del módulo: tiene que quedar quién la ejecutó.
        \App\Models\AuditLog::log([
            'action'      => 'invoice.deleted',
            'model_type'  => Invoice::class,
            'model_id'    => $invoice->id,
            'old_values'  => $invoice->only([
                'number', 'customer_id', 'total', 'balance_due',
                'status', 'period_start', 'period_end', 'invoice_type',
            ]),
            'description' => "Factura {$invoice->number} eliminada (no se regenerará para ese periodo).",
        ]);

        $this->billingService->deleteInvoice($invoice);

        return response()->json(['message' => 'Factura eliminada correctamente.']);
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

        // Stamp the staff user who registered the payment (from the auth token,
        // never trusting a client-supplied value).
        $data = $request->all();
        $data['created_by'] = $request->user()?->id;

        try {
            $payment = $this->billingService->registerPayment($data);
        } catch (\Throwable $e) {
            \Log::error('Error al registrar pago: ' . $e->getMessage(), [
                'customer_id' => $data['customer_id'] ?? null,
                'amount'      => $data['amount'] ?? null,
                'exception'   => get_class($e),
            ]);
            return response()->json([
                'message' => 'No se pudo registrar el pago: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json($payment->load(['allocations', 'creator:id,name,user_name,user_lastname']), 201);
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
            'items.*.unit'        => 'nullable|string|max:30',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.type'        => 'nullable|string|max:50',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',
            // Cargo de equipos, de TV, de reconexión... el operador elige del
            // catálogo; 'additional' sigue siendo el valor por defecto.
            'invoice_type'        => ['nullable', 'string', 'max:50', $this->invoiceTypeRule($request)],
        ]);

        $tenantId = $request->user()?->tenant_id;

        try {
            $invoice = $this->billingService->generateServiceChargeInvoice([
                'tenant_id'    => $tenantId,
                'customer_id'  => $data['customer_id'],
                'invoice_type' => $data['invoice_type'] ?? Invoice::TYPE_ADDITIONAL,
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

    /**
     * Regla de validación del tipo de factura: tiene que existir en el catálogo
     * del tenant (tipo del sistema o propio) y estar activo. Se rechaza el slug
     * de otro tenant, que si no permitiría etiquetar facturas con tipos ajenos.
     */
    private function invoiceTypeRule(Request $request): \Closure
    {
        $tenantId = $request->user()?->tenant_id;

        return function (string $attribute, $value, \Closure $fail) use ($tenantId) {
            if ($value === null || $value === '') {
                return;
            }

            if (!\App\Models\InvoiceType::isUsableSlug($value, $tenantId ? (int) $tenantId : null)) {
                $fail('El tipo de factura seleccionado no existe o está inactivo.');
            }
        };
    }

    // PDF Download
    public function downloadPdf($id)
    {
        $invoice = Invoice::with(['customer.customerProfile', 'items', 'tenant', 'ticket'])->findOrFail($id);

        $pdf = $this->templateRenderer->renderInvoice($invoice);
        return $pdf->download('Invoice-' . $invoice->number . '.pdf');
    }

    // Run Monthly Generation (Admin)
    public function runMonthlyGeneration(Request $request)
    {
        $period = $request->input('period'); // YYYY-MM
        $count = $this->billingService->generateMonthlyInvoices($period);
        return response()->json(['message' => "Generated $count invoices."]);
    }

    // Adjust customer credit balance (manual correction)
    public function updateCreditBalance(Request $request, $customerId)
    {
        $data = $request->validate([
            'credit_balance' => 'required|numeric|min:0',
            'reason'         => 'nullable|string|max:255',
        ]);

        $customer = \App\Models\CustomerProfile::where('user_id', $customerId)->firstOrFail();
        $previous = (float) $customer->credit_balance;

        $customer->credit_balance = $data['credit_balance'];
        $customer->save();

        \Log::info("Billing: Credit balance adjusted for customer {$customerId}. " .
            "Previous: {$previous}, New: {$data['credit_balance']}. " .
            "Reason: " . ($data['reason'] ?? 'no reason given') . ". " .
            "By user: " . $request->user()?->id);

        return response()->json([
            'credit_balance' => (float) $customer->credit_balance,
            'previous'       => $previous,
        ]);
    }

    // Customer Balance
    public function getCustomerBalance($customerId)
    {
        $balance       = (float) Invoice::where('customer_id', $customerId)->sum('balance_due');
        $customer      = \App\Models\CustomerProfile::where('user_id', $customerId)->first();
        $creditBalance = $customer ? (float) $customer->credit_balance : 0;
        $netBalance    = max(0, $balance - $creditBalance);

        // Saldo arrastrado: abonos parciales que cerraron su factura y todavía
        // no los ha cobrado ninguna factura nueva. NO se suma al saldo por
        // cobrar de hoy (el cliente no lo debe aún), pero el cajero tiene que
        // verlo: es plata que le va a llegar en la próxima factura.
        $pendingCarryover = \App\Models\InvoiceCarryover::pendingTotalFor((int) $customerId);

        return response()->json([
            'balance'          => $balance,
            'credit_balance'   => $creditBalance,
            'net_balance'      => $netBalance,
            'carryover_balance'=> $pendingCarryover,
        ]);
    }

    /**
     * Listado de recaudos: búsqueda general + filtros específicos por columna,
     * ordenamiento y paginación real.
     *
     * Antes solo existía `search` y el frontend nunca mandaba `page`, así que la
     * vista se quedaba clavada en los 10 recaudos más recientes: el resto solo
     * aparecía escribiendo en el buscador. Ahora se filtra por fecha, cliente,
     * monto, método, referencia y quién lo registró, y se recorren todas las
     * páginas.
     */
    public function getPayments(Request $request)
    {
        $f     = $this->validatedPaymentFilters($request);
        $query = $this->filteredPaymentsQuery($request, $f);

        $sortBy  = $f['sort_by'] ?? 'payment_date';
        $sortDir = $f['sort_dir'] ?? 'desc';

        // Agregados del listado, misma convención que Gastos y Facturación.
        //
        // Aquí NO se excluye ningún estado: a diferencia de facturas y gastos,
        // un recaudo no se anula — se elimina, y al eliminarlo se revierten sus
        // asignaciones (`deletePayment`). Lo que está en la tabla es dinero
        // efectivamente recibido.
        $summaryQuery = clone $query;

        $paginator = $query->orderBy($sortBy, $sortDir)
            ->orderBy('id', 'desc') // desempate estable entre páginas
            ->paginate($f['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($paginator->toArray() + [
            'summary' => [
                'total' => (float) (clone $summaryQuery)->sum('amount'),
                'count' => (clone $summaryQuery)->count(),
            ],
        ]);
    }

    /** Reglas de los filtros de recaudos, compartidas por el listado y la exportación. */
    private function validatedPaymentFilters(Request $request): array
    {
        return $request->validate([
            'search'        => 'nullable|string|max:255',
            'customer'      => 'nullable|string|max:255',
            'customer_id'   => 'nullable|integer',
            'reference'     => 'nullable|string|max:255',
            'method'        => 'nullable|string|max:100',
            'registered_by' => 'nullable|string|max:255',
            'invoice'       => 'nullable|string|max:100',
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date',
            'amount_min'    => 'nullable|numeric',
            'amount_max'    => 'nullable|numeric',
            'sort_by'       => 'nullable|in:payment_date,amount,method,reference,created_at',
            'sort_dir'      => 'nullable|in:asc,desc',
            'per_page'      => 'nullable|integer|min:1|max:200',
        ]);
    }

    /**
     * Consulta de recaudos con los filtros aplicados, SIN orden ni paginación.
     * Compartida por el listado y la exportación para que el CSV no pueda
     * divergir de lo que el usuario ve en pantalla.
     */
    private function filteredPaymentsQuery(Request $request, array $f)
    {
        // Sólo las columnas que pinta la tabla: `users` y `customer_profile`
        // tienen decenas de campos y traerlos enteros multiplicaba el peso de la
        // respuesta, sobre todo con per_page alto.
        $query = Payment::query()->with([
            'customer:id,user_name,email',
            'customer.customerProfile:user_id,name,last_name',
            'allocations:id,payment_id,invoice_id,amount',
            'allocations.invoice:id,number,invoice_type',
            'creator:id,name,user_name,user_lastname',
        ]);

        // Búsqueda general: referencia o cliente.
        if (!empty($f['search'])) {
            $search = $f['search'];
            $query->where(function ($q) use ($search) {
                $q->whereLike('reference', $search)
                    ->orWhereHas('customer', fn ($cq) => $this->applyCustomerSearch($cq, $search));
            });
        }

        // ── Filtros específicos por columna ───────────────────────────────────
        if (!empty($f['customer'])) {
            $query->whereHas('customer', fn ($cq) => $this->applyCustomerSearch($cq, $f['customer']));
        }

        if (!empty($f['customer_id'])) {
            $query->where('customer_id', $f['customer_id']);
        }

        if (!empty($f['reference'])) {
            $query->whereLike('reference', $f['reference']);
        }

        if (!empty($f['method'])) {
            $query->where('method', $f['method']);
        }

        // Columna "Facturas afectadas": número de alguna factura cubierta por
        // el recaudo.
        if (!empty($f['invoice'])) {
            $invoice = $f['invoice'];
            $query->whereHas('allocations.invoice', fn ($iq) => $iq->whereLike('number', $invoice));
        }

        if (!empty($f['date_from'])) {
            $query->whereDate('payment_date', '>=', $f['date_from']);
        }

        if (!empty($f['date_to'])) {
            $query->whereDate('payment_date', '<=', $f['date_to']);
        }

        // Ojo: 0 es un monto válido, por eso aquí sí se usa isset y no !empty.
        if (isset($f['amount_min'])) {
            $query->where('amount', '>=', $f['amount_min']);
        }

        if (isset($f['amount_max'])) {
            $query->where('amount', '<=', $f['amount_max']);
        }

        // Quién registró el recaudo. Los pagos automáticos (facturación de
        // instalación, etc.) no tienen creator: se filtran con "sistema".
        if (!empty($f['registered_by'])) {
            $registeredBy = trim($f['registered_by']);
            if (in_array(mb_strtolower($registeredBy), ['sistema', 'system', 'automatico', 'automático'], true)) {
                $query->whereNull('created_by');
            } else {
                $query->whereHas('creator', function ($uq) use ($registeredBy) {
                    $uq->where(function ($q) use ($registeredBy) {
                        $q->whereLike('user_name', $registeredBy)
                            ->orWhereLike('user_lastname', $registeredBy)
                            ->orWhereLike('name', $registeredBy)
                            ->orWhereLike("COALESCE(user_name, '') || ' ' || COALESCE(user_lastname, '')", $registeredBy);
                    });
                });
            }
        }

        // SECURITY FIX (OWASP A01): el tenant SIEMPRE sale del usuario
        // autenticado. Aceptarlo por query param permitía ver los recaudos de
        // otro tenant (y un `tenant=` vacío dejaba la lista en blanco).
        $tenantId = $request->user()?->tenant_id;
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Filtro de cliente reutilizado por la búsqueda general y por el filtro
     * específico "Cliente": nombre, apellido, nombre completo, cédula, usuario
     * o correo. El nombre completo va concatenado porque buscar "Juan Pérez"
     * no coincide con ninguna columna por separado.
     */
    private function applyCustomerSearch($query, string $term)
    {
        return $query->where(function ($cq) use ($term) {
            $cq->whereLike('user_name', $term)
                ->orWhereLike('email', $term)
                ->orWhereHas('customerProfile', function ($cpq) use ($term) {
                    $cpq->whereLike('name', $term)
                        ->orWhereLike('last_name', $term)
                        ->orWhereLike('cedula', $term)
                        ->orWhereLike("COALESCE(customer_profile.name, '') || ' ' || COALESCE(customer_profile.last_name, '')", $term);
                });
        });
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
            'create_invoice_time' => 'nullable|date_format:H:i,H:i:s',
            'payment_day' => 'nullable|date',
            'payment_reminder' => 'nullable|date',
            'payment_reminder_time' => 'nullable|date_format:H:i,H:i:s',
            'payment_reminder_enabled' => 'nullable|boolean',
            'cut_day' => 'nullable|date',
            'cut_time' => 'nullable|date_format:H:i,H:i:s',
            'overdue_invoices' => 'nullable|integer|min:1',
            // Margen del tope de facturación (null = sin tope, se factura siempre).
            'stop_invoicing_extra' => 'nullable|integer|min:0|max:60',
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
