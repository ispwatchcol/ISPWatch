<?php

namespace App\Http\Controllers;

use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerDocumentController extends Controller
{
    /**
     * Resolve a customer (users.id) and assert it belongs to the
     * authenticated user's tenant. Prevents cross-tenant access.
     */
    private function resolveCustomer(Request $request, $customerId): User
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return User::where('tenant_id', $tenantId)->findOrFail($customerId);
    }

    /**
     * List all documents for a customer.
     */
    public function index(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $documents = CustomerDocument::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * Upload one or more documents (photos / files) for a customer.
     */
    public function store(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $request->validate([
            'type'    => 'required|in:cedula,instalacion,contrato,otros',
            'files'   => 'required|array|min:1',
            'files.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp,pdf,doc,docx',
        ]);

        $created = [];

        foreach ($request->file('files') as $file) {
            $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
            $path = $file->storeAs(
                "customer_documents/{$customer->id}",
                $fileName,
                'public'
            );

            $created[] = CustomerDocument::create([
                'tenant_id'   => $customer->tenant_id,
                'customer_id' => $customer->id,
                'type'        => $request->input('type'),
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'signed'      => false,
                'uploaded_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message'   => count($created) . ' documento(s) subido(s) correctamente.',
            'documents' => $created,
        ], 201);
    }

    /**
     * Delete a document (record + stored file).
     */
    public function destroy(Request $request, $documentId)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $document = CustomerDocument::where('tenant_id', $tenantId)->findOrFail($documentId);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->json(['message' => 'Documento eliminado correctamente.']);
    }

    /**
     * Return the data needed to preview the service contract before signing.
     */
    public function contractData(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);
        $profile  = CustomerProfile::where('user_id', $customer->id)->first();
        $tenant   = Tenant::find($customer->tenant_id);
        $plan     = $profile?->service_id ? Plan::find($profile->service_id) : null;

        return response()->json([
            'customer' => [
                'name'      => $profile?->name ?? $customer->user_name,
                'last_name' => $profile?->last_name ?? $customer->user_lastname,
                'cedula'    => $profile?->cedula,
                'email'     => $customer->email,
                'tel'       => $customer->tel,
                'address'   => $profile?->address,
                'city'      => $profile?->city,
                'state'     => $profile?->state,
                'ip_user'   => $profile?->ip_user,
            ],
            'plan' => $plan ? [
                'name'         => $plan->name,
                'speed_down'   => $plan->speed_down,
                'speed_up'     => $plan->speed_up,
                'cost_product' => $plan->cost_product,
            ] : null,
            'company' => [
                'name'        => $tenant?->legal_name ?: $tenant?->name,
                'trade_name'  => $tenant?->trade_name,
                'nit'         => $tenant?->nit,
                'address'     => $tenant?->billing_address ?: $tenant?->address_tenant,
                'phone'       => $tenant?->billing_phone ?: $tenant?->tel_tenant,
                'email'       => $tenant?->billing_email ?: $tenant?->email_tenant,
                'city'        => $tenant?->city,
            ],
            'date' => now()->format('d/m/Y'),
        ]);
    }

    /**
     * Generate the signed contract PDF from the on-screen signature and
     * store it as a 'contrato' document.
     */
    public function signContract(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $data = $request->validate([
            'signature' => ['required', 'string', 'regex:/^data:image\/png;base64,/'],
        ]);

        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $tenant  = Tenant::find($customer->tenant_id);
        $plan    = $profile?->service_id ? Plan::find($profile->service_id) : null;

        $pdf = Pdf::loadView('documents.contract_pdf', [
            'customer'  => $customer,
            'profile'   => $profile,
            'tenant'    => $tenant,
            'plan'      => $plan,
            'signature' => $data['signature'],
            'date'      => now()->format('d/m/Y'),
        ]);

        $fileName = 'contrato_firmado_' . now()->format('Ymd_His') . '.pdf';
        $path = "customer_documents/{$customer->id}/{$fileName}";

        Storage::disk('public')->put($path, $pdf->output());

        $document = CustomerDocument::create([
            'tenant_id'   => $customer->tenant_id,
            'customer_id' => $customer->id,
            'type'        => 'contrato',
            'file_name'   => $fileName,
            'file_path'   => $path,
            'file_size'   => Storage::disk('public')->size($path),
            'mime_type'   => 'application/pdf',
            'signed'      => true,
            'uploaded_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message'  => 'Contrato firmado y guardado correctamente.',
            'document' => $document,
        ], 201);
    }
}
