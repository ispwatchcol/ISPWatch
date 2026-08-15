<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractAlreadySignedException;
use App\Models\ContractSignatureLink;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractSigningService;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Firma remota del contrato: SIN autenticación. El token del link ES la
 * autorización, así que aquí no hay `$request->user()` del que derivar el
 * tenant y cada método tiene que partir del link para saber de qué cliente
 * habla — nunca de un id que venga en la petición.
 *
 * Flujo de tres pasos, deliberado:
 *   1. show()   → el mínimo para pintar la portada (nombre del ISP y del
 *                 cliente de pila). Nada de cédula, dirección ni plan: quien
 *                 abra un link filtrado no debe cosechar datos personales.
 *   2. verify() → confirma los últimos 4 de la cédula y RECIÉN AHÍ devuelve el
 *                 contrato completo para leerlo.
 *   3. sign()   → vuelve a exigir esos 4 dígitos. Nunca confía en que el
 *                 cliente pasó por verify(): un POST directo a /sign se
 *                 salta el paso 2 sin despeinarse.
 */
class PublicContractController extends Controller
{
    public function __construct(
        private readonly ContractSigningService $signing,
        private readonly TemplateRenderer $templateRenderer,
    ) {
    }

    /**
     * Portada del link: qué se puede hacer con él y para quién es.
     */
    public function show(Request $request, string $token)
    {
        $link = $this->resolveLink($token);
        $customer = $this->customerOf($link);
        $tenant = Tenant::find($link->tenant_id);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();

        // Primera apertura: queda registrada para la constancia del PDF. No se
        // pisa en las siguientes — interesa CUÁNDO le llegó al cliente, no la
        // última vez que refrescó.
        if (!$link->opened_at) {
            $link->forceFill(['opened_at' => now()])->save();
        }

        $reason = $link->unusableReason();

        $payload = [
            'status'                => $reason ?? 'pending',
            'company_name'          => $tenant?->trade_name ?: ($tenant?->legal_name ?: $tenant?->name),
            'customer_first_name'   => $profile?->name ?: $customer->user_name,
            'requires_verification' => $this->verificationDigits($profile) !== null,
            'expires_at'            => $link->expires_at?->toIso8601String(),
        ];

        // Un link ya usado no es un error: es el cliente volviendo a buscar su
        // copia. Se le entrega el PDF firmado en vez de un cartel de "enlace
        // inválido" que le haría llamar a soporte.
        if ($link->isSigned() && $link->document) {
            $payload['contract_number'] = $link->document->contract_number;
            $payload['document_url'] = $link->document->url;
            $payload['signed_at'] = $link->signed_at?->toIso8601String();
        }

        return response()->json($payload);
    }

    /**
     * Confirma la identidad y entrega el contrato para leerlo.
     */
    public function verify(Request $request, string $token)
    {
        $link = $this->resolveLink($token);
        $this->assertUsable($link);

        $customer = $this->customerOf($link);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();

        $request->validate([
            'document_last4' => ['nullable', 'string', 'max:10'],
        ]);

        if (!$this->checkVerification($profile, $request->input('document_last4'))) {
            return $this->verificationFailed($link);
        }

        $link->forceFill(['verified_at' => now()])->save();

        $tenant = Tenant::find($link->tenant_id);
        $plan = $profile?->service_id ? Plan::find($profile->service_id) : null;

        return response()->json([
            'status'   => 'verified',
            'customer' => [
                'name'      => $profile?->name ?? $customer->user_name,
                'last_name' => $profile?->last_name ?? $customer->user_lastname,
                'cedula'    => $profile?->cedula,
                'address'   => $profile?->address,
                'city'      => $profile?->city,
            ],
            'plan' => $plan ? [
                'name'         => $plan->name,
                'speed_down'   => $plan->speed_down,
                'speed_up'     => $plan->speed_up,
                'cost_product' => $plan->cost_product,
            ] : null,
            'company' => [
                'name' => $tenant?->legal_name ?: $tenant?->name,
                'nit'  => $tenant?->nit,
            ],
            // El contrato COMPLETO tal como quedará impreso, para leerlo antes
            // de firmar. Sin firma ni consecutivo: ninguno de los dos existe
            // todavía y prometer un número que otra firma simultánea puede
            // llevarse sería mentir en un documento legal.
            'contract_html' => $this->contractHtml($customer, $profile, $tenant, $plan),
        ]);
    }

    /**
     * Firma y genera el PDF definitivo.
     */
    public function sign(Request $request, string $token)
    {
        $link = $this->resolveLink($token);
        $this->assertUsable($link);

        $data = $request->validate([
            'signature'      => ['required', 'string', 'regex:/^data:image\/png;base64,/'],
            'document_last4' => ['nullable', 'string', 'max:10'],
            'accepted'       => ['accepted'],
        ]);

        $customer = $this->customerOf($link);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();

        // Se vuelve a exigir aquí: verify() pudo no haberse llamado nunca.
        if (!$this->checkVerification($profile, $data['document_last4'] ?? null)) {
            return $this->verificationFailed($link);
        }

        try {
            $document = $this->signing->sign(
                $customer,
                $data['signature'],
                uploadedBy: null,
                link: $link,
                signerIp: $request->ip(),
                signerUserAgent: $request->userAgent(),
            );
        } catch (ContractAlreadySignedException $e) {
            // El ISP firmó presencialmente entre que se mandó el link y el
            // cliente lo abrió. El link ya no sirve para nada: se quema.
            $link->forceFill(['revoked_at' => now()])->save();

            return response()->json([
                'status'  => 'signed',
                'message' => 'Este contrato ya fue firmado. Comunícate con tu proveedor si necesitas una copia.',
            ], 409);
        }

        return response()->json([
            'status'          => 'signed',
            'message'         => 'Contrato firmado correctamente.',
            'contract_number' => $document->contract_number,
            'document_url'    => $document->url,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resuelve el link por su token, saltándose el scope de tenant a propósito.
     *
     * Esta ruta es pública, pero `EnsureFrontendRequestsAreStateful` está activo:
     * si quien abre el enlace resulta tener una sesión del panel en el mismo
     * navegador, `auth()->check()` es true y el global scope de BelongsToTenant
     * filtraría por el tenant de ESA sesión. Un operador del ISP A abriendo el
     * link del ISP B vería un 404 inexplicable, y el flujo depende de que el
     * enlace funcione igual para cualquiera que lo tenga.
     *
     * Saltarse el scope aquí no abre ninguna puerta: el tenant no se toma de la
     * petición sino del propio link, y llegar hasta esta consulta exige conocer
     * el token en claro, que sólo existe en el mensaje que recibió el cliente.
     */
    private function resolveLink(string $token): ContractSignatureLink
    {
        $link = ContractSignatureLink::withoutGlobalScope('tenant')
            ->with(['document' => fn ($q) => $q->withoutGlobalScope('tenant')])
            ->where('token_hash', ContractSignatureLink::hashToken($token))
            ->first();

        abort_if(!$link, 404, 'El enlace no es válido.');

        return $link;
    }

    private function customerOf(ContractSignatureLink $link): User
    {
        $customer = User::find($link->customer_id);

        // El cliente se borró después de emitir el link.
        abort_if(!$customer, 404, 'El enlace no es válido.');

        return $customer;
    }

    /**
     * 409 en vez de 404: el link existe y es de quien dice ser, sólo que ya no
     * se puede firmar con él. El front necesita distinguirlo para mostrar
     * "ya firmaste" en vez de "enlace inválido".
     */
    private function assertUsable(ContractSignatureLink $link): void
    {
        $reason = $link->unusableReason();

        if ($reason === null) {
            return;
        }

        abort(response()->json([
            'status'  => $reason,
            'message' => match ($reason) {
                'signed'  => 'Este contrato ya fue firmado.',
                'revoked' => 'Este enlace fue anulado por tu proveedor. Pídele uno nuevo.',
                'expired' => 'Este enlace venció. Pídele uno nuevo a tu proveedor.',
                'locked'  => 'Demasiados intentos fallidos. Pídele un enlace nuevo a tu proveedor.',
                default   => 'El enlace no es válido.',
            },
        ], 409));
    }

    /**
     * Los 4 dígitos que hay que confirmar, o null si este cliente no tiene
     * cédula registrada. Sin cédula NO se puede exigir la verificación: dejaría
     * al cliente encerrado fuera de su propio contrato sin forma de entrar.
     */
    private function verificationDigits(?CustomerProfile $profile): ?string
    {
        $cedula = preg_replace('/\D/', '', (string) ($profile?->cedula ?? ''));

        return strlen($cedula) >= 4 ? substr($cedula, -4) : null;
    }

    private function checkVerification(?CustomerProfile $profile, ?string $provided): bool
    {
        $expected = $this->verificationDigits($profile);

        if ($expected === null) {
            return true;
        }

        $given = preg_replace('/\D/', '', (string) $provided);

        return hash_equals($expected, substr($given, -4));
    }

    private function verificationFailed(ContractSignatureLink $link)
    {
        $link->increment('failed_attempts');
        $link->refresh();

        $remaining = max(0, ContractSignatureLink::MAX_FAILED_ATTEMPTS - $link->failed_attempts);

        return response()->json([
            'status'    => $remaining > 0 ? 'invalid_verification' : 'locked',
            'message'   => $remaining > 0
                ? "Los datos no coinciden. Te quedan {$remaining} intento(s)."
                : 'Demasiados intentos fallidos. Pídele un enlace nuevo a tu proveedor.',
            'remaining' => $remaining,
        ], 422);
    }

    /**
     * El contrato como HTML para leerlo en el celular. Si el render falla
     * (plantilla del tenant rota, por ejemplo) NO se tumba la página: el
     * cliente puede seguir firmando y queda el rastro en logs. Bloquear la
     * firma por un fallo de maquetación sería el peor de los dos males.
     */
    private function contractHtml(User $customer, ?CustomerProfile $profile, ?Tenant $tenant, ?Plan $plan): ?string
    {
        if (!$tenant) {
            return null;
        }

        try {
            $html = $this->templateRenderer->renderContractHtml(
                $customer,
                $profile,
                $tenant,
                $plan,
                '',
                now()->format('d/m/Y'),
                null,
                null
            );

            return $this->inlineLocalImages($html);
        } catch (\Throwable $e) {
            Log::error('No se pudo renderizar el contrato para la firma remota.', [
                'customer_id' => $customer->id,
                'tenant_id'   => $tenant->id,
                'exception'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convierte a data URI las imágenes que el PDF referencia por RUTA LOCAL
     * (el logo del tenant): dompdf las lee del disco, pero el navegador del
     * cliente no puede, y el contrato se vería con un icono de imagen rota
     * justo en el encabezado.
     *
     * Sólo se inlinean archivos bajo public/storage y con extensión de imagen.
     * Esa restricción no es cosmética: en modo avanzado el HTML lo escribe el
     * tenant, así que sin ella un `<img src="/etc/passwd">` convertiría esta
     * vista previa en una lectura arbitraria de archivos del servidor.
     */
    private function inlineLocalImages(string $html): string
    {
        $root = str_replace('\\', '/', realpath(public_path('storage')) ?: '');

        if ($root === '') {
            return $html;
        }

        return preg_replace_callback(
            '/src=(["\'])([^"\']+)\1/i',
            function (array $m) use ($root): string {
                $raw = $m[2];

                // data: y http(s): ya funcionan en el navegador tal cual.
                if (preg_match('#^(data:|https?:|//)#i', $raw)) {
                    return $m[0];
                }

                $real = realpath(str_replace('\\', '/', $raw));

                if ($real === false) {
                    return $m[0];
                }

                $real = str_replace('\\', '/', $real);

                if (!str_starts_with($real, $root . '/')) {
                    return $m[0];
                }

                $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));

                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                    return $m[0];
                }

                $bytes = @file_get_contents($real);

                if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
                    return $m[0];
                }

                $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' ? 'image/jpeg' : "image/{$ext}");

                return 'src="data:' . $mime . ';base64,' . base64_encode($bytes) . '"';
            },
            $html
        ) ?? $html;
    }
}
