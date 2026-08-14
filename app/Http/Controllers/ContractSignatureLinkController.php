<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractAlreadySignedException;
use App\Mail\ContractSignatureLinkMail;
use App\Models\ContractSignatureLink;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractSigningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emisión de links de firma remota (lado ISP). El cliente los consume sin
 * autenticarse en PublicContractController.
 *
 * NO existe un endpoint de "reenviar el mismo link", y es a propósito: el
 * token sólo se guarda hasheado, así que ni el servidor puede recuperarlo. Un
 * reenvío es siempre un link nuevo, y emitirlo revoca el anterior — dos links
 * vivos a la vez sólo sirven para que el cliente firme por el que nadie está
 * siguiendo.
 */
class ContractSignatureLinkController extends Controller
{
    public function __construct(private readonly ContractSigningService $signing)
    {
    }

    /**
     * Historial de links del cliente, del más reciente al más viejo.
     */
    public function index(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $links = ContractSignatureLink::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ContractSignatureLink $link) => $this->present($link));

        return response()->json($links);
    }

    /**
     * Emite un link y, si se pide, lo envía por correo.
     */
    public function store(Request $request, $customerId)
    {
        $customer = $this->resolveCustomer($request, $customerId);

        $data = $request->validate([
            'channel'    => ['nullable', 'in:email,whatsapp,manual'],
            'ttl_hours'  => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $channel = $data['channel'] ?? 'manual';

        try {
            ['link' => $link, 'token' => $token] = $this->signing->issueLink(
                $customer,
                createdBy: $request->user()?->id,
                ttlHours: (int) ($data['ttl_hours'] ?? ContractSignatureLink::DEFAULT_TTL_HOURS),
            );
        } catch (ContractAlreadySignedException $e) {
            return response()->json([
                'message'              => $e->getMessage(),
                'existing_document_id' => $e->existing->id,
            ], 409);
        }

        $url = ContractSigningService::signingUrl($token);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $tenant = Tenant::find($customer->tenant_id);

        $mailSent = false;
        $mailError = null;

        if ($channel === 'email') {
            // El correo de contacto, NO email_tenant: ese es el usuario de
            // acceso autogenerado (nombre.apellido@dominio) y no es una casilla
            // que el cliente lea. Ver User::sanitizeEmail.
            $to = $customer->email;

            if (!$to) {
                $mailError = 'El cliente no tiene correo registrado.';
            } else {
                [$mailSent, $mailError] = $this->sendMail($to, $customer, $profile, $tenant, $url, $link);
            }

            $link->forceFill([
                'sent_channel' => 'email',
                'sent_to'      => $to,
                'sent_at'      => $mailSent ? now() : null,
            ])->save();
        } elseif ($channel === 'whatsapp') {
            // El envío por WhatsApp lo dispara el operador desde su propio
            // teléfono con el enlace wa.me — ver whatsappUrl(). Aquí sólo se
            // deja anotado el destino; sent_at se queda en null porque este
            // servidor no envió nada y afirmar lo contrario ensuciaría la
            // constancia del contrato.
            $link->forceFill([
                'sent_channel' => 'whatsapp',
                'sent_to'      => $customer->tel,
            ])->save();
        }

        return response()->json([
            'message'      => $this->storeMessage($channel, $mailSent, $mailError),
            'link'         => $this->present($link->fresh()),
            'url'          => $url,
            'whatsapp_url' => $this->whatsappUrl($customer, $profile, $tenant, $url),
            'mail_sent'    => $mailSent,
            'mail_error'   => $mailError,
        ], 201);
    }

    /**
     * Anula un link vivo (el cliente perdió el celular, se mandó al número
     * equivocado, el dato del contrato estaba mal).
     */
    public function destroy(Request $request, $linkId)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $link = ContractSignatureLink::where('tenant_id', $tenantId)->findOrFail($linkId);

        if ($link->isSigned()) {
            return response()->json([
                'message' => 'Ese enlace ya se usó para firmar; no se puede anular.',
            ], 409);
        }

        $link->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'Enlace anulado.']);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Mismo criterio que CustomerDocumentController: el cliente se resuelve
     * dentro del tenant del usuario autenticado, nunca por id suelto.
     */
    private function resolveCustomer(Request $request, $customerId): User
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return User::where('tenant_id', $tenantId)->findOrFail($customerId);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function sendMail($to, User $customer, ?CustomerProfile $profile, ?Tenant $tenant, string $url, ContractSignatureLink $link): array
    {
        try {
            Mail::to($to)->send(new ContractSignatureLinkMail(
                customerName: trim(($profile?->name ?? $customer->user_name) . ' ' . ($profile?->last_name ?? $customer->user_lastname ?? '')),
                companyName: $tenant?->trade_name ?: ($tenant?->legal_name ?: ($tenant?->name ?: 'tu proveedor de internet')),
                signingUrl: $url,
                expiresAt: $link->expires_at->timezone(config('app.timezone'))->format('d/m/Y \a \l\a\s H:i'),
            ));

            return [true, null];
        } catch (\Throwable $e) {
            // El link YA existe y es válido: que el SMTP falle no puede
            // invalidarlo ni devolver un 500. El operador copia la URL y lo
            // manda por donde quiera.
            Log::error('No se pudo enviar el correo con el link de firma.', [
                'customer_id' => $customer->id,
                'link_id'     => $link->id,
                'exception'   => $e->getMessage(),
            ]);

            return [false, 'No se pudo enviar el correo. Copia el enlace y envíalo manualmente.'];
        }
    }

    private function storeMessage(string $channel, bool $mailSent, ?string $mailError): string
    {
        if ($channel === 'email') {
            return $mailSent
                ? 'Enlace generado y enviado por correo.'
                : 'Enlace generado, pero el correo no salió. ' . $mailError;
        }

        if ($channel === 'whatsapp') {
            return 'Enlace generado. Se abrirá WhatsApp con el mensaje listo para enviar.';
        }

        return 'Enlace generado. Cópialo y envíaselo al cliente.';
    }

    /**
     * Deep link de WhatsApp con el mensaje ya escrito. Se usa esto y no la API
     * de Meta a propósito: la API sólo deja iniciar conversaciones con
     * plantillas aprobadas por Meta una a una, mientras que wa.me funciona
     * siempre, desde el teléfono del operador y sin trámite previo.
     */
    private function whatsappUrl(User $customer, ?CustomerProfile $profile, ?Tenant $tenant, string $url): ?string
    {
        $phone = preg_replace('/\D/', '', (string) $customer->tel);

        if (!$phone) {
            return null;
        }

        $countryCode = (string) config('services.whatsapp.default_country_code', '57');

        // Un número nacional de 10 dígitos se antepone el indicativo; uno que
        // ya lo trae se deja como está.
        if (strlen($phone) === 10 && !str_starts_with($phone, $countryCode)) {
            $phone = $countryCode . $phone;
        }

        $name = $profile?->name ?: $customer->user_name;
        $company = $tenant?->trade_name ?: ($tenant?->legal_name ?: ($tenant?->name ?: 'tu proveedor de internet'));

        $text = "Hola {$name}, soy de {$company}. Te comparto el enlace para leer y firmar tu contrato de servicio "
            . "desde el celular: {$url} — Es personal y de un solo uso. Te pediremos los últimos 4 dígitos de tu cédula.";

        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
    }

    /**
     * El token NUNCA sale aquí: sólo existe en la respuesta del store que lo
     * acaba de generar. Un listado que lo devolviera convertiría el permiso de
     * ver clientes en la capacidad de firmar por ellos.
     */
    private function present(ContractSignatureLink $link): array
    {
        return [
            'id'              => $link->id,
            'status'          => $link->unusableReason() ?? 'pending',
            'expires_at'      => $link->expires_at?->toIso8601String(),
            'sent_channel'    => $link->sent_channel,
            'sent_to'         => $link->sent_to,
            'sent_at'         => $link->sent_at?->toIso8601String(),
            'opened_at'       => $link->opened_at?->toIso8601String(),
            'signed_at'       => $link->signed_at?->toIso8601String(),
            'signer_ip'       => $link->signer_ip,
            'failed_attempts' => $link->failed_attempts,
            'created_at'      => $link->created_at?->toIso8601String(),
        ];
    }
}
