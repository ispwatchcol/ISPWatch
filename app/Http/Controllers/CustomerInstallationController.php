<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Services\InstallationBillingService;
use App\Models\CustomerDocument;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerInstallationController extends Controller
{
    private function authTenant(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');
        return $tenantId;
    }

    private function resolveCustomer(Request $request, $customerId): User
    {
        $tenantId = $this->authTenant($request);
        return User::where('tenant_id', $tenantId)->findOrFail($customerId);
    }

    private function resolveInstallation(Request $request, $installationId): CustomerInstallation
    {
        $tenantId = $this->authTenant($request);
        return CustomerInstallation::where('tenant_id', $tenantId)->findOrFail($installationId);
    }

    /**
     * Validate that a technician_id belongs to a user in the same tenant
     * with the role code 'technician'. Returns null if input is null.
     */
    private function validateTechnician(?int $technicianId, int $tenantId): ?int
    {
        if (!$technicianId) return null;

        $valid = User::where('id', $technicianId)
            ->where('tenant_id', $tenantId)
            ->whereHas('role', function ($q) {
                $q->where('code', 'technician');
            })
            ->exists();

        abort_if(!$valid, 422, 'El técnico seleccionado no pertenece al tenant o no tiene rol de técnico.');
        return $technicianId;
    }

    /**
     * Returns true when the authenticated user is allowed to see/edit billing fields.
     * Mirrors CustomerInstallationPolicy::hasFinancialAccess() for collection contexts
     * where a model instance isn't available (all(), index() list endpoints).
     */
    private function userCanViewBilling(Request $request): bool
    {
        $user = $request->user();
        if ((int) $user->role_id === 1) {
            return true;
        }
        $user->loadMissing('role');
        return $user->role?->hasPermission(Permissions::EDIT_DISCOUNT) ?? false;
    }

    /**
     * Format one installation and apply billing-field access control for the
     * authenticated user. Use this in every endpoint that returns an installation
     * object — it is the single point where role-based field filtering happens.
     */
    private function formatRowForUser(Request $request, CustomerInstallation $inst): array
    {
        $row = $this->formatRow($inst);
        $canBilling = $this->userCanViewBilling($request);
        $row['can_edit_billing'] = $canBilling;
        if (!$canBilling) {
            $row = $this->stripBillingFields($row);
        }
        return $row;
    }

    /**
     * Remove the 11 billing/cartera fields from a formatted row array.
     * Used to sanitize list and detail responses for non-financial roles.
     */
    private function stripBillingFields(array $row): array
    {
        foreach ([
            'payment_agreement', 'installation_cost', 'additional_charges', 'additional_items',
            'discount', 'discount_reason', 'payment_method', 'payment_received',
            'payment_notes', 'customer_retention', 'special_attention', 'promotion_notes',
        ] as $field) {
            unset($row[$field]);
        }
        return $row;
    }

    /**
     * Format one installation with related names. Falls back to prospect data
     * when the installation isn't yet linked to a real customer.
     */
    private function formatRow(CustomerInstallation $inst): array
    {
        $profile  = $inst->customer?->customerProfile;
        $prospect = $inst->prospect;
        $tech     = $inst->technicianUser;

        $name = trim(($profile?->name ?? '') . ' ' . ($profile?->last_name ?? ''))
            ?: $inst->customer?->email
            ?: ($prospect ? trim(($prospect->name ?? '') . ' ' . ($prospect->last_name ?? '')) : null);

        $row = $inst->toArray();
        // Remove the nested invoice object to prevent full financial data leaking
        // into every response. Safe metadata fields are extracted explicitly below.
        unset($row['invoice']);

        return array_merge($row, [
            'customer_name'  => $name,
            'customer_email' => $inst->customer?->email ?? $prospect?->email,
            'customer_tel'   => $inst->customer?->tel   ?? $prospect?->tel,
            'is_prospect'    => !$inst->customer_id,
            'prospect_name'  => $prospect ? trim(($prospect->name ?? '') . ' ' . ($prospect->last_name ?? '')) : null,
            'technician_name' => $tech
                ? trim(($tech->user_name ?? '') . ' ' . ($tech->user_lastname ?? '')) ?: $tech->name
                : ($inst->technician ?: null),
            'invoice_id'     => $inst->relationLoaded('invoice') ? $inst->invoice?->id     : null,
            'invoice_number' => $inst->relationLoaded('invoice') ? $inst->invoice?->number : null,
            'invoice_status' => $inst->relationLoaded('invoice') ? $inst->invoice?->status : null,
        ]);
    }

    public function all(Request $request)
    {
        $tenantId   = $this->authTenant($request);
        $canBilling = $this->userCanViewBilling($request);

        $installations = CustomerInstallation::with(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])
            ->where('tenant_id', $tenantId)
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->from,   fn($q, $d) => $q->whereDate('scheduled_date', '>=', $d))
            ->when($request->to,     fn($q, $d) => $q->whereDate('scheduled_date', '<=', $d))
            ->orderBy('scheduled_date', 'desc')
            ->get()
            ->map(function ($i) use ($canBilling) {
                $row = $this->formatRow($i);
                $row['can_edit_billing'] = $canBilling;
                if (!$canBilling) {
                    $row = $this->stripBillingFields($row);
                }
                return $row;
            });

        return response()->json($installations);
    }

    public function index(Request $request, $customerId)
    {
        $customer   = $this->resolveCustomer($request, $customerId);
        $canBilling = $this->userCanViewBilling($request);

        $installations = CustomerInstallation::with(['technicianUser', 'invoice'])
            ->where('customer_id', $customer->id)
            ->orderBy('scheduled_date', 'desc')
            ->get()
            ->map(function ($i) use ($canBilling) {
                $row = $this->formatRow($i);
                $row['can_edit_billing'] = $canBilling;
                if (!$canBilling) {
                    $row = $this->stripBillingFields($row);
                }
                return $row;
            });

        return response()->json($installations);
    }

    public function show(Request $request, $installationId)
    {
        $inst = $this->resolveInstallation($request, $installationId);
        $inst->load(['customer.customerProfile', 'prospect', 'technicianUser', 'documents', 'invoice']);

        return response()->json($this->formatRowForUser($request, $inst));
    }

    public function store(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $data = $request->validate([
            'scheduled_date' => 'required|date',
            'technician_id'  => 'nullable|integer',
            'technician'     => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'in:pendiente,completada,cancelada',
        ]);

        $data['technician_id'] = $this->validateTechnician(
            $data['technician_id'] ?? null,
            $customer->tenant_id
        );

        $installation = CustomerInstallation::create([
            'tenant_id'      => $customer->tenant_id,
            'customer_id'    => $customer->id,
            'scheduled_date' => $data['scheduled_date'],
            'technician_id'  => $data['technician_id'],
            'technician'     => $data['technician'] ?? null,
            'address'        => $data['address'] ?? null,
            'equipment'      => $data['equipment'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'status'         => $data['status'] ?? 'pendiente',
            'completed_at'   => ($data['status'] ?? 'pendiente') === 'completada' ? now() : null,
            'created_by'     => $request->user()?->id,
        ]);

        return response()->json([
            'message'      => 'Orden de instalación creada correctamente.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ], 201);
    }

    /**
     * Create a prospect and schedule its first installation in one shot.
     * This is the single entry point the Installations view uses — keeps
     * the user flow as "agendar instalación" without exposing prospects as
     * a separate concept in the UI.
     */
    public function createWithProspect(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $data = $request->validate([
            // Prospect data
            'name'           => 'required|string|max:120',
            'last_name'      => 'nullable|string|max:120',
            'cedula'         => 'nullable|string|max:40',
            'email'          => 'nullable|email|max:180',
            'tel'            => 'nullable|string|max:40',
            'address'        => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:120',
            'state'          => 'nullable|string|max:120',
            'estrato'        => 'nullable|integer|between:1,6',
            'prospect_notes' => 'nullable|string|max:2000',
            // Installation data
            'scheduled_date' => 'required|date',
            'technician_id'  => 'nullable|integer',
            'technician'     => 'nullable|string|max:120',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $data['technician_id'] = $this->validateTechnician($data['technician_id'] ?? null, $tenantId);

        $installation = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tenantId, $request) {
            $prospect = Prospect::create([
                'tenant_id'  => $tenantId,
                'name'       => $data['name'],
                'last_name'  => $data['last_name'] ?? null,
                'cedula'     => $data['cedula'] ?? null,
                'email'      => $data['email'] ?? null,
                'tel'        => $data['tel'] ?? null,
                'address'    => $data['address'] ?? null,
                'city'       => $data['city'] ?? null,
                'state'      => $data['state'] ?? null,
                'estrato'    => $data['estrato'] ?? null,
                'notes'      => $data['prospect_notes'] ?? null,
                'status'     => 'agendado',
                'created_by' => $request->user()?->id,
            ]);

            return CustomerInstallation::create([
                'tenant_id'      => $tenantId,
                'prospect_id'    => $prospect->id,
                'scheduled_date' => $data['scheduled_date'],
                'technician_id'  => $data['technician_id'],
                'technician'     => $data['technician'] ?? null,
                'address'        => $data['address'] ?? null,
                'equipment'      => $data['equipment'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'status'         => 'pendiente',
                'created_by'     => $request->user()?->id,
            ]);
        });

        return response()->json([
            'message'      => 'Prospecto e instalación creados correctamente.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ], 201);
    }

    /**
     * Update the prospect tied to an installation (inline edit from the list).
     */
    public function updateProspect(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);
        abort_if(!$installation->prospect_id, 422, 'Esta instalación no está ligada a un prospecto.');

        $prospect = $installation->prospect;
        abort_if(!$prospect, 404, 'Prospecto no encontrado.');

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'cedula'    => 'nullable|string|max:40',
            'email'     => 'nullable|email|max:180',
            'tel'       => 'nullable|string|max:40',
            'address'   => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:120',
            'state'     => 'nullable|string|max:120',
            'estrato'   => 'nullable|integer|between:1,6',
            'notes'     => 'nullable|string|max:2000',
        ]);

        $prospect->update($data);

        return response()->json([
            'message'      => 'Prospecto actualizado.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ]);
    }

    /**
     * Schedule an installation for a prospect (no customer yet).
     */
    public function storeForProspect(Request $request, $prospectId)
    {
        $tenantId = $this->authTenant($request);
        $prospect = Prospect::where('tenant_id', $tenantId)->findOrFail($prospectId);

        $data = $request->validate([
            'scheduled_date' => 'required|date',
            'technician_id'  => 'nullable|integer',
            'technician'     => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'in:pendiente,completada,cancelada',
        ]);

        $data['technician_id'] = $this->validateTechnician(
            $data['technician_id'] ?? null,
            $tenantId
        );

        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenantId,
            'prospect_id'    => $prospect->id,
            'scheduled_date' => $data['scheduled_date'],
            'technician_id'  => $data['technician_id'],
            'technician'     => $data['technician'] ?? null,
            'address'        => $data['address'] ?? $prospect->address,
            'equipment'      => $data['equipment'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'status'         => $data['status'] ?? 'pendiente',
            'completed_at'   => ($data['status'] ?? 'pendiente') === 'completada' ? now() : null,
            'created_by'     => $request->user()?->id,
        ]);

        // Move the prospect into "agendado" once it has at least one scheduled install.
        if ($prospect->status === 'interesado') {
            $prospect->update(['status' => 'agendado']);
        }

        return response()->json([
            'message'      => 'Instalación agendada al prospecto.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ], 201);
    }

    public function update(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        $data = $request->validate([
            'scheduled_date' => 'required|date',
            'technician_id'  => 'nullable|integer',
            'technician'     => 'nullable|string|max:120',
            'address'        => 'nullable|string|max:255',
            'equipment'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'status'         => 'in:pendiente,completada,cancelada',
        ]);

        if (array_key_exists('technician_id', $data)) {
            $data['technician_id'] = $this->validateTechnician(
                $data['technician_id'],
                $installation->tenant_id
            );
        }

        if (isset($data['status']) && $data['status'] === 'completada' && $installation->status !== 'completada') {
            $data['completed_at'] = now();
        } elseif (isset($data['status']) && $data['status'] !== 'completada') {
            $data['completed_at'] = null;
        }

        $installation->update($data);

        return response()->json([
            'message'      => 'Orden de instalación actualizada correctamente.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ]);
    }

    /**
     * Update billing/cartera fields only.
     * Primary gate: permission:edit_discount middleware in api.php.
     * Secondary gate: CustomerInstallationPolicy::updateFinancialData (belt-and-suspenders).
     * Side-effect: creates or updates the linked invoice via InstallationBillingService.
     */
    public function updateBilling(Request $request, $installationId, InstallationBillingService $billingService)
    {
        $installation = $this->resolveInstallation($request, $installationId);
        $this->authorize('updateFinancialData', $installation);

        $data = $request->validate([
            'payment_agreement'  => 'nullable|boolean',
            'installation_cost'  => 'nullable|numeric|min:0',
            'additional_charges' => 'nullable|numeric|min:0',
            'additional_items'                => 'nullable|array',
            'additional_items.*.description'  => 'required|string|max:255',
            'additional_items.*.amount'       => 'required|numeric|min:0',
            'discount'           => 'nullable|numeric|min:0',
            'discount_reason'    => [
                'nullable', 'string', 'max:255',
                Rule::requiredIf(fn () => (float) ($request->input('discount') ?? 0) > 0),
            ],
            'payment_method'     => 'nullable|string|max:50',
            'payment_received'   => 'nullable|numeric|min:0',
            'payment_notes'      => 'nullable|string',
            'customer_retention' => 'nullable|boolean',
            'special_attention'  => 'nullable|boolean',
            'promotion_notes'    => 'nullable|string',
        ]);

        // additional_charges siempre refleja la suma de los adicionales itemizados
        // cuando el cliente los envía; así los reportes agregados siguen cuadrando.
        if (array_key_exists('additional_items', $data)) {
            $items = collect($data['additional_items'] ?? [])
                ->map(fn ($it) => [
                    'description' => (string) $it['description'],
                    'amount'      => round((float) $it['amount'], 2),
                ])
                ->values()
                ->all();
            $data['additional_items']   = $items ?: null;
            $data['additional_charges'] = array_sum(array_column($items, 'amount'));
        }

        $installation->update($data);
        $installation->refresh();

        $invoice        = null;
        $invoiceWarning = null;

        if ($installation->customer_id) {
            $invoice = $billingService->upsertInstallationInvoice($installation, $installation->tenant_id);
        } else {
            $invoiceWarning = 'No se generó factura: la instalación no tiene un cliente asignado aún.';
        }

        return response()->json([
            'message'         => 'Información de cartera actualizada correctamente.',
            'installation'    => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
            'invoice'         => $invoice?->load('items'),
            'invoice_warning' => $invoiceWarning,
        ]);
    }

    public function destroy(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        foreach (['customer_signature_path', 'technician_signature_path'] as $col) {
            if ($installation->{$col}) {
                Storage::disk('public')->delete($installation->{$col});
            }
        }
        $installation->delete();

        return response()->json(['message' => 'Orden de instalación eliminada correctamente.']);
    }

    /**
     * Save the installation sheet (cable, módem, ONU, signal, etc.).
     */
    public function saveSheet(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        $data = $request->validate([
            'sheet'                  => 'required|array',
            'sheet.cable_meters'     => 'nullable|numeric',
            'sheet.modem_brand'      => 'nullable|string|max:80',
            'sheet.modem_model'      => 'nullable|string|max:80',
            'sheet.modem_mac'        => 'nullable|string|max:80',
            'sheet.onu_serial'       => 'nullable|string|max:120',
            'sheet.signal_level'     => 'nullable|string|max:40',
            'sheet.antenna_model'    => 'nullable|string|max:120',
            'sheet.materials'        => 'nullable|string|max:1000',
            'sheet.observations'     => 'nullable|string|max:2000',
            'sheet.inventory_device_id'  => 'nullable|integer',
            // Conexión / red
            'sheet.sectorial_id'         => 'nullable|integer',
            'sheet.router_id'            => 'nullable|integer',
            'sheet.plan_id'              => 'nullable|integer',
            'sheet.client_ip'            => 'nullable|string|max:64',
            'sheet.pppoe_username'       => 'nullable|string|max:120',
            'sheet.pppoe_password'       => 'nullable|string|max:120',
            'sheet.pppoe_local_address'  => 'nullable|string|max:64',
            'sheet.local_address_manual' => 'nullable|boolean',
            'sheet.vlan'                 => 'nullable|string|max:20',
        ]);

        $installation->update(['sheet' => $data['sheet']]);

        return response()->json([
            'message'      => 'Hoja de instalación guardada.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
        ]);
    }

    /**
     * Resolve the storage folder for an installation. Prospect-only installs
     * land under prospects/{id}; customer-linked ones under customer_documents/{id}.
     */
    private function storageFolder(CustomerInstallation $installation): string
    {
        return $installation->customer_id
            ? "customer_documents/{$installation->customer_id}"
            : "prospect_documents/{$installation->prospect_id}";
    }

    /**
     * Upload installation photos. They are stored as customer_documents tagged
     * with installation_id and type=instalacion.
     */
    public function uploadPhotos(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp',
        ]);

        $folder = $this->storageFolder($installation);
        $created = [];
        foreach ($request->file('files') as $file) {
            $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs($folder, $fileName, 'public');

            $created[] = CustomerDocument::create([
                'tenant_id'       => $installation->tenant_id,
                'customer_id'     => $installation->customer_id,
                'installation_id' => $installation->id,
                'type'            => 'instalacion',
                'file_name'       => $file->getClientOriginalName(),
                'file_path'       => $path,
                'file_size'       => $file->getSize(),
                'mime_type'       => $file->getMimeType(),
                'signed'          => false,
                'uploaded_by'     => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message'   => count($created) . ' foto(s) subida(s) correctamente.',
            'documents' => $created,
        ], 201);
    }

    /**
     * Sign the installation: store both signatures, render the
     * "Hoja de instalación" PDF, store it as a document, mark completed.
     */
    public function sign(Request $request, $installationId)
    {
        $installation = $this->resolveInstallation($request, $installationId);

        $data = $request->validate([
            'customer_signature'   => ['required', 'string', 'regex:/^data:image\/png;base64,/'],
            'technician_signature' => ['nullable', 'string', 'regex:/^data:image\/png;base64,/'],
        ]);

        $custPath = $this->storeSignature($data['customer_signature'], $installation, 'cliente');
        $techPath = !empty($data['technician_signature'])
            ? $this->storeSignature($data['technician_signature'], $installation, 'tecnico')
            : null;

        $installation->update([
            'customer_signature_path'   => $custPath,
            'technician_signature_path' => $techPath ?? $installation->technician_signature_path,
            'signed_at'                 => now(),
            'status'                    => 'completada',
            'completed_at'              => now(),
        ]);

        // If this install belongs to a prospect, promote it to "instalado".
        if ($installation->prospect && $installation->prospect->status !== 'convertido') {
            $installation->prospect->update(['status' => 'instalado']);
        }

        $installation->load(['customer.customerProfile', 'prospect', 'technicianUser']);
        $document = $this->renderSheetPdf($installation, $data['customer_signature'], $data['technician_signature'] ?? null);

        return response()->json([
            'message'      => 'Instalación firmada y completada.',
            'installation' => $this->formatRowForUser($request, $installation->fresh(['customer.customerProfile', 'prospect', 'technicianUser', 'invoice'])),
            'document'     => $document,
        ]);
    }

    private function storeSignature(string $base64Png, CustomerInstallation $installation, string $who): string
    {
        $clean = preg_replace('/^data:image\/png;base64,/', '', $base64Png);
        $bytes = base64_decode($clean);
        $folder = $this->storageFolder($installation);
        $path = "{$folder}/installations/{$installation->id}/firma_{$who}_" . time() . ".png";
        Storage::disk('public')->put($path, $bytes);
        return $path;
    }

    private function renderSheetPdf(CustomerInstallation $installation, string $custSig, ?string $techSig): CustomerDocument
    {
        $customer = $installation->customer;
        $profile  = $customer?->customerProfile;
        $prospect = $installation->prospect;
        $tenant   = Tenant::find($installation->tenant_id);
        $tech     = $installation->technicianUser;

        $photos = CustomerDocument::where('installation_id', $installation->id)
            ->where('type', 'instalacion')
            ->get();

        $sheet = $installation->sheet ?? [];
        $sectorial = !empty($sheet['sectorial_id']) ? \App\Models\Sectorial::find($sheet['sectorial_id']) : null;
        $router    = !empty($sheet['router_id'])    ? \App\Models\Router::find($sheet['router_id'])       : null;
        $plan      = !empty($sheet['plan_id'])      ? \App\Models\Plan::find($sheet['plan_id'])           : null;

        $pdf = Pdf::loadView('documents.installation_sheet_pdf', [
            'installation'         => $installation,
            'customer'             => $customer,
            'profile'              => $profile,
            'prospect'             => $prospect,
            'tenant'               => $tenant,
            'technician'           => $tech,
            'photos'               => $photos,
            'sectorial'            => $sectorial,
            'router'               => $router,
            'plan'                 => $plan,
            'customer_signature'   => $custSig,
            'technician_signature' => $techSig,
            'date'                 => now()->format('d/m/Y H:i'),
        ]);

        $folder = $this->storageFolder($installation);
        $fileName = 'hoja_instalacion_' . $installation->id . '_' . now()->format('Ymd_His') . '.pdf';
        $path = "{$folder}/{$fileName}";

        Storage::disk('public')->put($path, $pdf->output());

        return CustomerDocument::create([
            'tenant_id'       => $installation->tenant_id,
            'customer_id'     => $installation->customer_id,
            'installation_id' => $installation->id,
            'type'            => 'instalacion',
            'file_name'       => $fileName,
            'file_path'       => $path,
            'file_size'       => Storage::disk('public')->size($path),
            'mime_type'       => 'application/pdf',
            'signed'          => true,
            'uploaded_by'     => auth()->id(),
        ]);
    }

    /**
     * List users with the technician role in the current tenant.
     * Used by the installations UI dropdown.
     */
    public function technicians(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $techs = User::with('role')
            ->where('tenant_id', $tenantId)
            ->where('status', true)
            ->whereHas('role', function ($q) {
                $q->where('code', 'technician');
            })
            ->orderBy('user_name')
            ->get()
            ->map(fn($u) => [
                'id'        => $u->id,
                'name'      => trim(($u->user_name ?? '') . ' ' . ($u->user_lastname ?? '')) ?: $u->name,
                'email'     => $u->email,
                'tel'       => $u->tel,
                'role_name' => $u->role?->name,
            ]);

        return response()->json($techs);
    }

    /**
     * Light list of customers in the tenant (for the installation create modal).
     */
    public function customersForInstallation(Request $request)
    {
        $tenantId = $this->authTenant($request);

        $rows = CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select(
                'customer_profile.user_id as id',
                'customer_profile.name',
                'customer_profile.last_name',
                'customer_profile.address',
                'customer_profile.cedula',
                'users.email',
                'users.tel'
            )
            ->orderBy('customer_profile.name')
            ->get()
            ->map(fn($r) => [
                'id'        => $r->id,
                'full_name' => trim(($r->name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email,
                'email'     => $r->email,
                'tel'       => $r->tel,
                'address'   => $r->address,
                'cedula'    => $r->cedula,
            ]);

        return response()->json($rows);
    }
}
