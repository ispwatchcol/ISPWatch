{{--
    Constancia de firma electrónica. Se imprime SÓLO cuando el contrato se
    firmó de forma remota por link — en la firma presencial hay un empleado
    que presencia el acto y el contrato sale como salía antes.

    Lo que la hace útil no es el recuadro: es que la misma información queda
    en contract_signature_links (IP, hora de apertura, hora de firma) y el
    SHA-256 del PDF en customer_documents.content_sha256, de modo que el papel
    se pueda contrastar contra el registro. Ver
    App\Services\ContractSigningService::buildAudit().
--}}
<div style="margin-top:18px; border:1px solid #d1d5db; border-radius:4px; padding:8px 10px; font-size:9px; color:#4b5563; line-height:1.5;">
    <strong style="display:block; margin-bottom:3px; color:#1f2937; font-size:9px;">CONSTANCIA DE FIRMA ELECTRÓNICA</strong>
    El presente documento fue firmado electrónicamente por EL CLIENTE de forma remota, mediante enlace personal
    de un solo uso enviado por el proveedor. Datos de la firma:
    <table style="width:100%; border-collapse:collapse; margin-top:4px;">
        <tr>
            <td style="padding:1px 4px; color:#6b7280;">Fecha y hora</td>
            <td style="padding:1px 4px;">{{ $audit['signed_at'] ?? '—' }} ({{ $audit['timezone'] ?? '' }})</td>
        </tr>
        @if(!empty($audit['opened_at']))
            <tr>
                <td style="padding:1px 4px; color:#6b7280;">Enlace abierto</td>
                <td style="padding:1px 4px;">{{ $audit['opened_at'] }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:1px 4px; color:#6b7280;">Dirección IP</td>
            <td style="padding:1px 4px;">{{ $audit['ip'] ?? '—' }}</td>
        </tr>
        <tr>
            <td style="padding:1px 4px; color:#6b7280;">Dispositivo</td>
            <td style="padding:1px 4px;">{{ $audit['user_agent'] ?? '—' }}</td>
        </tr>
        @if(!empty($audit['sent_to']))
            <tr>
                <td style="padding:1px 4px; color:#6b7280;">Enlace enviado a</td>
                <td style="padding:1px 4px;">{{ $audit['sent_to'] }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:1px 4px; color:#6b7280;">Referencia</td>
            <td style="padding:1px 4px;">FIRMA-{{ $audit['link_id'] ?? '—' }}</td>
        </tr>
    </table>
</div>
