<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Models\Billing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OverdueSuspensionService;
use App\Services\Templates\TemplateRenderer;
use App\Traits\ExportsCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    /** Reglas de los filtros de facturas, compartidas por el listado y la exportación. */
    private function validatedInvoiceFilters(Request $request): array
    {
        return $request->validate([
            'search'       => 'nullable|string|max:255',
            'number'       => 'nullable|string|max:100',
            'customer'     => 'nullable|string|max:255',
            'customer_id'  => 'nullable|integer',
            'status'       => 'nullable|string|max:30',
            'invoice_type' => 'nullable|string|max:50',
            'period'       => 'nullable|string|max:20',
            'due_from'     => 'nullable|date',
            'due_to'       => 'nullable|date',
            'total_min'    => 'nullable|numeric',
            'total_max'    => 'nullable|numeric',
            'balance_min'  => 'nullable|numeric',
            'balance_max'  => 'nullable|numeric',
            'sort_by'      => 'nullable|in:issue_date,due_date,number,total,balance_due,status,invoice_type',
            'sort_dir'     => 'nullable|in:asc,desc',
            'per_page'     => 'nullable|integer|min:1|max:200',
        ]);
    }

    /**
     * Consulta de facturas con los filtros del listado aplicados, SIN orden ni
     * paginación.
     *
     * Vive aparte porque la usan el listado y la exportación: si cada uno
     * armara sus propios filtros, acabarían divergiendo y el CSV dejaría de
     * corresponder a lo que el usuario tenía en pantalla — que es justo lo que
     * la exportación promete.
     *
     * Además de la búsqueda general hay un filtro por cada columna de la tabla,
     * igual que en Recaudos: con un solo `search` la única forma de revisar las
     * facturas emitidas era escribir en el buscador y esperar que el término
     * cayera en el número o en el nombre.
     */
    private function filteredInvoicesQuery(Request $request, array $f)
    {
        $query = Invoice::query()->with(['customer.customerProfile']);

        // Búsqueda general: número o cliente.
        if (!empty($f['search'])) {
            $search = $f['search'];
            $query->where(function ($q) use ($search) {
                $q->whereLike('number', $search)
                    ->orWhereHas('customer', fn ($cq) => $this->applyCustomerSearch($cq, $search));
            });
        }

        // ── Filtros específicos por columna ───────────────────────────────────
        if (!empty($f['number'])) {
            $query->whereLike('number', $f['number']);
        }

        if (!empty($f['customer'])) {
            $query->whereHas('customer', fn ($cq) => $this->applyCustomerSearch($cq, $f['customer']));
        }

        if (!empty($f['customer_id'])) {
            $query->where('customer_id', $f['customer_id']);
        }

        if (!empty($f['status'])) {
            $query->where('status', $f['status']);
        }

        if (!empty($f['invoice_type'])) {
            $query->where('invoice_type', $f['invoice_type']);
        }

        if (!empty($f['period'])) {
            try {
                $period = Carbon::parse($f['period'])->format('Y-m');
                $query->where('period_start', 'like', $period . '%');
            } catch (\Exception $e) {
                $query->where('period_start', 'like', $f['period'] . '%');
            }
        }

        if (!empty($f['due_from'])) {
            $query->whereDate('due_date', '>=', $f['due_from']);
        }

        if (!empty($f['due_to'])) {
            $query->whereDate('due_date', '<=', $f['due_to']);
        }

        // Ojo: 0 es un importe válido (factura en cero, saldo saldado), por eso
        // aquí se usa isset y no !empty.
        if (isset($f['total_min'])) {
            $query->where('total', '>=', $f['total_min']);
        }

        if (isset($f['total_max'])) {
            $query->where('total', '<=', $f['total_max']);
        }

        if (isset($f['balance_min'])) {
            $query->where('balance_due', '>=', $f['balance_min']);
        }

        if (isset($f['balance_max'])) {
            $query->where('balance_due', '<=', $f['balance_max']);
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
        $f     = $this->validatedInvoiceFilters($request);
        $query = $this->filteredInvoicesQuery($request, $f);

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
        // repetir u omitir la misma factura. Vale para cualquier columna de
        // orden, no sólo la de por defecto.
        $paginator = $query->orderBy($f['sort_by'] ?? 'issue_date', $f['sort_dir'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate($f['per_page'] ?? 20)
            ->withQueryString();

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
        $f = $this->validatedInvoiceFilters($request);

        $query = $this->filteredInvoicesQuery($request, $f)
            ->orderBy($f['sort_by'] ?? 'issue_date', $f['sort_dir'] ?? 'desc')
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
            // Concepto de la línea de detalle. Opcional: si no viene, se deriva
            // del tipo de factura.
            'description' => 'nullable|string|max:255',
        ]);

        // Sin tipo explícito se mantiene el comportamiento histórico (la columna
        // nace con default 'monthly'), pero el formulario ya lo manda siempre.
        $data['invoice_type'] = $data['invoice_type'] ?? Invoice::TYPE_MONTHLY;

        $description = trim((string) ($data['description'] ?? '')) ?: $this->defaultItemDescription($data);
        unset($data['description']);

        $total = $data['total'] ?? 0;
        $data['status']      = 'issued';
        $data['subtotal']    = $total;
        $data['total']       = $total;
        $data['balance_due'] = $total;
        $data['currency']    = 'COP';

        // Todo o nada: la factura, su ítem y el saldo aplicado son un solo
        // hecho. A medias dejaría justo los estados que este cambio viene a
        // eliminar — una factura sin detalle, o un saldo descontado del cliente
        // que no llegó a bajar ningún saldo.
        $invoice = DB::transaction(function () use ($data, $total, $description) {
            // Generate invoice number using BillingService
            $data['number'] = $this->billingService->generateInvoiceNumber($data['tenant_id']);

            $invoice = Invoice::create($data);

            // La factura nace CON su línea de detalle. Sin esto el PDF salía con
            // la tabla de ítems vacía y un total suelto al pie: el cliente
            // recibía un documento que no dice qué se le está cobrando. La
            // automática siempre creó su ítem; sólo el alta manual se quedaba
            // sin él.
            if ($total > 0) {
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'type'        => $this->itemTypeFor($data['invoice_type']),
                    'description' => $description,
                    'quantity'    => 1,
                    'unit_price'  => $total,
                    'amount'      => $total,
                ]);
            }

            // Y consume el saldo a favor del cliente, igual que la automática.
            // Sin esto, quien borraba una factura pagada y creaba otra en su
            // lugar dejaba al cliente con saldo a favor Y con la factura nueva
            // debiendo entera: el dinero seguía en el sistema pero no pagaba
            // nada, y el cliente aparecía debiendo lo que ya había pagado.
            return $this->billingService->applyCreditToManualInvoice($invoice);
        });

        return response()->json($invoice->fresh('items'), 201);
    }

    /**
     * Concepto por defecto de la línea de una factura manual: el nombre del tipo
     * en el catálogo del tenant y, si es mensual, el mes que cubre.
     */
    private function defaultItemDescription(array $data): string
    {
        $slug = $data['invoice_type'];

        $nombre = \App\Models\InvoiceType::forTenant((int) $data['tenant_id'])
            ->where('slug', $slug)
            ->value('name') ?: ucfirst(str_replace('_', ' ', $slug));

        if ($slug === Invoice::TYPE_MONTHLY && !empty($data['period_start'])) {
            try {
                return $nombre . ': ' . Carbon::parse($data['period_start'])->translatedFormat('F \d\e Y');
            } catch (\Exception $e) {
                // Fecha rara: mejor el nombre a secas que reventar el alta.
            }
        }

        return $nombre;
    }

    /** Tipo de ítem coherente con el tipo de factura (`invoice_items.type`). */
    private function itemTypeFor(string $invoiceType): string
    {
        return match ($invoiceType) {
            Invoice::TYPE_MONTHLY => 'plan',
            'installation'        => 'service',
            default               => 'charge',
        };
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

        // `reactivation` sale del servicio (no es una columna): le dice al cajero
        // si el cliente estaba cortado y si quedó reconectado. Se agrega como
        // clave suelta del JSON para no tocar la forma que ya consume el front
        // (allocations, creator, …).
        $body = $payment->load(['allocations', 'creator:id,name,user_name,user_lastname'])->toArray();
        $body['reactivation'] = $payment->reactivation;

        return response()->json($body, 201);
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

        // El ajuste entra por el libro de movimientos: queda con autor, motivo y
        // saldo resultante. Antes solo se escribía a un Log::info de archivo,
        // que en producción rota y se pierde — es decir, tocar el saldo a mano
        // era la única operación de plata que no dejaba rastro recuperable.
        \App\Models\CustomerCredit::adjust(
            (int) $customerId,
            (float) $data['credit_balance'],
            $previous,
            $data['reason'] ?? null
        );

        $customer->refresh();

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

        // Estado de corte: el cajero tiene que saber ANTES de cobrar que el
        // cliente está suspendido, y que registrar el pago lo va a reconectar.
        $suspension = $this->billingService->suspensionStatusFor((int) $customerId);

        return response()->json([
            'balance'          => $balance,
            'credit_balance'   => $creditBalance,
            'net_balance'      => $netBalance,
            'carryover_balance'=> $pendingCarryover,
            'suspension'       => $suspension,
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

    /**
     * Panel de Finanzas.
     *
     * Las cifras son de UN MES, salvo la cartera. La distinción no es cosmética:
     * facturado, recaudado y gastos son **flujo** y sólo significan algo dentro
     * de un periodo, mientras que el pendiente es un **saldo** — lo que te deben
     * es todo lo que te deben, y recortarlo al mes escondería precisamente la
     * mora vieja, que es la que duele. Antes el panel no filtraba nada y las
     * cuatro tarjetas eran el acumulado histórico del ISP.
     *
     * Tres exclusiones que antes faltaban y movían los números:
     *  - facturas `void`/`cancelled`: inflaban lo facturado sin aportar pagos, y
     *    por tanto hundían la tasa de cobro. El listado de facturas ya las
     *    excluía, así que el panel y el listado no cuadraban entre sí.
     *  - pagos `void`: sumaban plata que se había anulado.
     *  - gastos `anulado`.
     */
    public function getStats(Request $request)
    {
        // El tenant sale del usuario autenticado, NUNCA del query param: antes
        // llegaba como `?tenant=`, así que cualquiera con view_billing podía
        // pedir las finanzas de otra empresa cambiando un número en la URL.
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $start = empty($data['month'])
            ? Carbon::now()->startOfMonth()
            : Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $totalInvoiced = (float) Invoice::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereBetween('issue_date', [$start, $end])
            ->sum('total');

        $totalPaid = (float) Payment::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('payment_date', [$start, $end])
            ->sum('amount');

        // La cartera es acumulada a propósito (ver el docblock).
        $totalPending = (float) Invoice::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->sum('balance_due');

        $canSeeExpenses = $this->userCanViewExpenses($request);
        $totalExpenses = $canSeeExpenses
            ? (float) Expense::where('tenant_id', $tenantId)
                ->where('status', Expense::STATUS_ACTIVE)
                ->whereBetween('expense_date', [$start, $end])
                ->sum('amount')
            : null;

        $recentInvoices = Invoice::where('tenant_id', $tenantId)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereBetween('issue_date', [$start, $end])
            ->with('customer.customerProfile')
            ->orderBy('created_at', 'desc')->limit(5)->get();

        $recentPayments = Payment::where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('payment_date', [$start, $end])
            ->with('customer.customerProfile')
            ->orderBy('created_at', 'desc')->limit(5)->get();

        return response()->json([
            'period' => [
                'month' => $start->format('Y-m'),
                'label' => $this->monthLabel($start),
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
                'is_current_month' => $start->isSameMonth(Carbon::now()),
            ],
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_paid'     => $totalPaid,
                'total_expenses' => $totalExpenses,
                // Balance de CAJA: lo que entró menos lo que salió. No es
                // facturado − gastos (causación), porque una factura emitida y
                // no pagada no sirve para cubrir la nómina.
                'balance'        => $canSeeExpenses ? round($totalPaid - $totalExpenses, 2) : null,
                'total_pending'  => $totalPending,
                'collection_rate' => $this->collectionRate($tenantId, $start, $end, $totalInvoiced),
                'can_view_expenses' => $canSeeExpenses,
            ],
            'recent_invoices' => $recentInvoices,
            'recent_payments' => $recentPayments,
            'currency' => '$'
        ]);
    }

    /**
     * Qué porcentaje de LO FACTURADO EN EL MES está pagado.
     *
     * Se mide contra los pagos imputados a esas facturas (`payment_allocations`),
     * no contra el total recaudado en el mes: si se cobra mora vieja, ese dinero
     * pertenece a facturas de meses anteriores y meterlo aquí daría tasas por
     * encima del 100% que no significan nada.
     */
    private function collectionRate(int $tenantId, Carbon $start, Carbon $end, float $totalInvoiced): float
    {
        if ($totalInvoiced <= 0) {
            return 0;
        }

        $collected = (float) DB::table('payment_allocations')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('invoices.tenant_id', $tenantId)
            ->whereNotIn('invoices.status', ['void', 'cancelled'])
            ->whereBetween('invoices.issue_date', [$start, $end])
            ->where('payments.status', 'completed')
            ->sum('payment_allocations.amount');

        return round(($collected / $totalInvoiced) * 100, 2);
    }

    /**
     * Los gastos son de otro permiso (`view_expenses`): el rol Contabilidad los
     * ve y un rol sólo-facturación no. Devolver null en vez de 0 permite que el
     * panel esconda las tarjetas en vez de mostrar un balance falso.
     */
    private function userCanViewExpenses(Request $request): bool
    {
        $user = $request->user();

        if ((int) $user->role_id === 1) {
            return true;
        }

        $user->loadMissing('role');

        return $user->role?->hasPermission(Permissions::VIEW_EXPENSES) ?? false;
    }

    /** "Agosto 2026" — Carbon no localiza los meses sin configurar el locale. */
    private function monthLabel(Carbon $date): string
    {
        $months = [
            1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];

        return $months[$date->month] . ' ' . $date->year;
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
