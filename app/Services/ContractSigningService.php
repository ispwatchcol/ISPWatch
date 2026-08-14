<?php

namespace App\Services;

use App\Exceptions\ContractAlreadySignedException;
use App\Models\ContractSignatureLink;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Único punto donde se genera un contrato firmado.
 *
 * Existe porque hay DOS entradas a la misma operación —
 * CustomerDocumentController::signContract (firma presencial, con un empleado
 * autenticado) y PublicContractController::sign (firma remota, autorizada por
 * el token del link) — y todo lo delicado es común: el candado de un solo
 * contrato vigente, la reserva del consecutivo con lockForUpdate y el orden
 * entre reservar, renderizar y guardar. Duplicar eso en dos controladores
 * significaba, tarde o temprano, dos huecos distintos en la numeración.
 */
class ContractSigningService
{
    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
        private readonly ContractNumberService $contractNumbers,
    ) {
    }

    /** El contrato firmado vigente del cliente, si lo tiene. */
    public function existingSignedContract(User $customer): ?CustomerDocument
    {
        return CustomerDocument::where('customer_id', $customer->id)
            ->where('type', 'contrato')
            ->where('signed', true)
            ->first();
    }

    /**
     * Genera, almacena y registra el contrato firmado.
     *
     * @param  string $signature  data URI PNG en base64 de la firma trazada.
     * @param  ?ContractSignatureLink $link  presente sólo en la firma remota:
     *         además de marcarse como usado, es lo que hace que el PDF lleve
     *         impresa la constancia de firma electrónica.
     *
     * @throws ContractAlreadySignedException
     */
    public function sign(
        User $customer,
        string $signature,
        ?int $uploadedBy = null,
        ?ContractSignatureLink $link = null,
        ?string $signerIp = null,
        ?string $signerUserAgent = null,
    ): CustomerDocument {
        // Se comprueba ANTES de reservar el consecutivo: rechazar después
        // gastaría un número de la secuencia en un contrato que nunca existe.
        $existing = $this->existingSignedContract($customer);

        if ($existing) {
            throw new ContractAlreadySignedException($existing);
        }

        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $tenant  = Tenant::find($customer->tenant_id);

        if (!$tenant) {
            throw new RuntimeException("El cliente {$customer->id} no tiene un tenant válido.");
        }

        $plan     = $profile?->service_id ? Plan::find($profile->service_id) : null;
        $signedAt = now();
        $date     = $signedAt->format('d/m/Y');

        // El consecutivo se reserva ANTES de renderizar: va impreso dentro del
        // PDF, así que no puede asignarse después de generarlo.
        $contractNumber = $this->contractNumbers->allocate((int) $customer->tenant_id);

        $pdf = $this->templateRenderer->renderContract(
            $customer,
            $profile,
            $tenant,
            $plan,
            $signature,
            $date,
            $contractNumber,
            $link ? $this->buildAudit($link, $signedAt, $signerIp, $signerUserAgent) : null
        );

        $bytes = $pdf->output();

        // El consecutivo es texto libre elegido por el ISP; el nombre del
        // archivo se deriva saneado de él (ver ContractNumberService::fileName).
        $fileName = ContractNumberService::fileName($contractNumber);
        $path = "customer_documents/{$customer->id}/{$fileName}";

        Storage::disk('s3')->put($path, $bytes);

        // La huella se calcula sobre los MISMOS bytes que se subieron, no
        // releyendo de S3: si el objeto se corrompiera al escribirse, un hash
        // tomado de la relectura documentaría la corrupción como si fuera lo
        // firmado.
        $sha256 = hash('sha256', $bytes);

        return DB::transaction(function () use (
            $customer, $fileName, $path, $bytes, $sha256, $contractNumber, $uploadedBy, $link, $signedAt, $signerIp, $signerUserAgent
        ) {
            $document = CustomerDocument::create([
                'tenant_id'       => $customer->tenant_id,
                'customer_id'     => $customer->id,
                'type'            => 'contrato',
                'file_name'       => $fileName,
                'file_path'       => $path,
                'file_size'       => strlen($bytes),
                'mime_type'       => 'application/pdf',
                'signed'          => true,
                'contract_number' => $contractNumber,
                'content_sha256'  => $sha256,
                'uploaded_by'     => $uploadedBy,
            ]);

            if ($link) {
                $link->forceFill([
                    'signed_at'         => $signedAt,
                    'signer_ip'         => $signerIp,
                    'signer_user_agent' => $signerUserAgent ? mb_substr($signerUserAgent, 0, 512) : null,
                    'document_id'       => $document->id,
                ])->save();
            }

            return $document;
        });
    }

    /**
     * Datos de la constancia que se imprime al pie del contrato remoto.
     *
     * El hash del PDF NO va aquí a propósito: se calcula sobre el archivo ya
     * renderizado, así que imprimirlo dentro del propio archivo sería una
     * referencia circular (el hash cambiaría al incluirlo). Vive en
     * customer_documents.content_sha256, que es donde se verifica.
     */
    private function buildAudit(
        ContractSignatureLink $link,
        \Illuminate\Support\Carbon $signedAt,
        ?string $signerIp,
        ?string $signerUserAgent,
    ): array {
        return [
            'link_id'    => $link->id,
            'signed_at'  => $signedAt->timezone(config('app.timezone'))->format('d/m/Y H:i:s'),
            'timezone'   => config('app.timezone'),
            'ip'         => $signerIp ?: '—',
            'user_agent' => $signerUserAgent ? mb_substr($signerUserAgent, 0, 180) : '—',
            'sent_to'    => $link->sent_to,
            'opened_at'  => $link->opened_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s'),
        ];
    }

    /**
     * Emite un link de firma para el cliente y revoca los que siguieran
     * vivos: dos links válidos a la vez sólo sirven para que el cliente firme
     * por el viejo, que es el que ya no se está siguiendo.
     *
     * Devuelve el token EN CLARO junto al registro — es la única vez que
     * existe; en la BD sólo queda su SHA-256.
     *
     * @return array{link: ContractSignatureLink, token: string}
     */
    public function issueLink(
        User $customer,
        ?int $createdBy = null,
        int $ttlHours = ContractSignatureLink::DEFAULT_TTL_HOURS,
    ): array {
        $existing = $this->existingSignedContract($customer);

        if ($existing) {
            throw new ContractAlreadySignedException($existing);
        }

        $token = ContractSignatureLink::generateToken();

        $link = DB::transaction(function () use ($customer, $createdBy, $ttlHours, $token) {
            ContractSignatureLink::where('customer_id', $customer->id)
                ->usable()
                ->update(['revoked_at' => now()]);

            return ContractSignatureLink::create([
                'tenant_id'   => $customer->tenant_id,
                'customer_id' => $customer->id,
                'token_hash'  => ContractSignatureLink::hashToken($token),
                'expires_at'  => now()->addHours($ttlHours),
                'created_by'  => $createdBy,
            ]);
        });

        return ['link' => $link, 'token' => $token];
    }

    /** URL pública que se le manda al cliente. */
    public static function signingUrl(string $token): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/firmar/' . $token;
    }
}
