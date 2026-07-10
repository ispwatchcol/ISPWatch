@php
    $statusMap = [
        'draft'     => 'BORRADOR',
        'issued'    => 'EMITIDA',
        'paid'      => 'PAGADA',
        'partial'   => 'PAGO PARCIAL',
        'void'      => 'ANULADA',
        'overdue'   => 'VENCIDA',
        'cancelled' => 'CANCELADA',
    ];
    $statusEs = $statusMap[strtolower($invoice->status)] ?? strtoupper($invoice->status);

    $statusColor = [
        'draft'     => '#6b7280',
        'issued'    => '#1e5fa8',
        'paid'      => '#15803d',
        'partial'   => '#b45309',
        'void'      => '#6b7280',
        'overdue'   => '#dc2626',
        'cancelled' => '#6b7280',
    ][strtolower($invoice->status)] ?? '#1e5fa8';

    $invoiceType  = $invoice->invoice_type ?? 'monthly';
    $isCharge     = in_array($invoiceType, ['service_charge', 'additional']);
    $typeLabel    = match($invoiceType) {
        'service_charge' => 'CARGO POR SERVICIO',
        'additional'     => 'CARGO ADICIONAL',
        default          => 'DE VENTA DE SERVICIOS',
    };

    $tenant      = $invoice->tenant;
    $companyName = $tenant->legal_name ?? $tenant->name ?? 'ISP Provider';
    $tradeName   = $tenant->trade_name ?? '';
    $nit         = $tenant->nit
        ? ('NIT: ' . $tenant->nit . ($tenant->nit_verification_digit ? '-' . $tenant->nit_verification_digit : ''))
        : '';
    $taxRegime   = $tenant->tax_regime ?? '';
    $phone       = $tenant->billing_phone ?? $tenant->tel_tenant ?? '';
    $email       = $tenant->billing_email  ?? $tenant->email_tenant ?? '';
    $address     = $tenant->billing_address ?? $tenant->address_tenant ?? '';
    $city        = $tenant->city ?? $tenant->zone_tenant ?? '';
    $department  = $tenant->department ?? '';

    $customer    = $invoice->customer;
    $profile     = $customer->customerProfile ?? null;
    $custName    = trim($customer->name . ' ' . ($customer->last_name ?? ''));
    $custCedula  = $profile?->cedula ?? '';
    $custAddress = $profile?->address ?? '';
    $custCity    = $profile?->city ?? '';
    $custPhone   = $profile?->phone ?? $customer->phone ?? '';

    $ticket = $invoice->ticket ?? null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $invoice->number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        table { border-collapse: collapse; }

        /* ── HEADER ── */
        .hdr        { background: #003087; }
        .hdr-left   { padding: 14px 16px; vertical-align: middle; width: 60%; }
        .hdr-right  { padding: 14px 16px; vertical-align: middle; width: 40%; text-align: right; }
        .co-name    { font-size: 16px; font-weight: bold; color: #ffffff; }
        .co-sub     { font-size: 8.5px; color: #b8d0f0; margin-top: 2px; line-height: 1.6; }
        .inv-title  { font-size: 22px; font-weight: bold; color: #ffffff; letter-spacing: 2px; }
        .inv-sub    { font-size: 9px;  color: #b8d0f0; margin-top: 1px; }
        .inv-num    { font-size: 14px; font-weight: bold; color: #ffd700; margin-top: 5px; }

        /* ── STATUS BAR ── */
        .sbar       { background: #0050b3; }
        .sbar td    { padding: 5px 16px; font-size: 9px; color: #ffffff; }

        /* ── INFO BOXES ── */
        .info-wrap  { padding: 10px 14px; }
        .info-box   { border: 1px solid #c5d9f0; padding: 8px 10px; background: #f7fbff; vertical-align: top; }
        .box-hd     { font-size: 8px; font-weight: bold; color: #003087; text-transform: uppercase;
                      border-bottom: 1px solid #c5d9f0; padding-bottom: 3px; margin-bottom: 5px; }
        .info-row   { font-size: 9.5px; margin-bottom: 2px; line-height: 1.5; }
        .lbl        { color: #555555; }

        /* ── ITEMS ── */
        .items-wrap { padding: 0 14px 10px; position: relative; }
        .it-hd th   { background: #003087; color: #fff; padding: 7px 8px; font-size: 9px; font-weight: bold; }
        .it-hd th.r { text-align: right; }
        .it-hd th.c { text-align: center; }
        .it-row td  { padding: 6px 8px; font-size: 9.5px; border-bottom: 1px solid #dce8f5; }
        .it-row td.r { text-align: right; }
        .it-row td.c { text-align: center; }
        .it-alt     { background: #f0f6ff; }

        /* ── TOTALS ── */
        .tot-wrap   { padding: 0 14px 12px; }
        .tot-tbl    { width: 260px; border: 1px solid #c5d9f0; margin-left: auto; }
        .tot-sub    { background: #f7fbff; border-bottom: 1px solid #dce8f5; }
        .tot-tax    { background: #f7fbff; border-bottom: 1px solid #dce8f5; }
        .tot-grand  { background: #003087; color: #ffffff; }
        .tot-grand td { padding: 7px 10px; font-size: 11px; font-weight: bold; }
        .tot-bal    { background: #e6f0ff; }
        .tot-bal td { font-weight: bold; color: #003087; }
        .tot-tbl td { padding: 5px 10px; font-size: 9.5px; }
        .tot-tbl td:last-child { text-align: right; font-weight: bold; }

        /* ── PAID STAMP ── */
        .stamp { position: absolute; top: 8px; right: 20px;
                 border: 3px solid #15803d; color: #15803d;
                 font-size: 20px; font-weight: bold;
                 padding: 6px 12px; letter-spacing: 3px;
                 transform: rotate(-20deg); opacity: 0.80; }

        /* ── FOOTER ── */
        .footer { border-top: 2px solid #003087; padding: 6px 14px 0;
                  font-size: 7.5px; color: #777; text-align: center; margin-top: 8px; }
    </style>
</head>
<body>

{{-- ══ CABECERA ══ --}}
<table width="100%" class="hdr">
    <tr>
        <td class="hdr-left">
            <div class="co-name">{{ $companyName }}</div>
            @if($tradeName && $tradeName !== $companyName)
                <div class="co-sub">{{ $tradeName }}</div>
            @endif
            @if($nit)
                <div class="co-sub">{{ $nit }}@if($taxRegime) &nbsp;·&nbsp; Régimen: {{ $taxRegime }}@endif</div>
            @endif
            @if($address || $city)
                <div class="co-sub">{{ $address }}@if($city) · {{ $city }}@endif@if($department) · {{ $department }}@endif</div>
            @endif
            @if($phone || $email)
                <div class="co-sub">@if($phone)Tel: {{ $phone }}@endif@if($phone && $email) &nbsp;·&nbsp; @endif@if($email){{ $email }}@endif</div>
            @endif
        </td>
        <td class="hdr-right">
            <div class="inv-title">FACTURA</div>
            <div class="inv-sub">{{ $typeLabel }}</div>
            <div class="inv-num">N° {{ $invoice->number }}</div>
        </td>
    </tr>
</table>

{{-- ══ BARRA DE ESTADO ══ --}}
<table width="100%" class="sbar">
    <tr>
        <td style="width:38%;">
            @if($isCharge && $ticket)
                <strong>Ticket Ref.:</strong> #{{ $ticket->id }} — {{ $ticket->subject }}
            @else
                <strong>Período:</strong>
                {{ $invoice->period_start ? \Carbon\Carbon::parse($invoice->period_start)->format('d/m/Y') : '—' }}
                al
                {{ $invoice->period_end ? \Carbon\Carbon::parse($invoice->period_end)->format('d/m/Y') : '—' }}
            @endif
        </td>
        <td style="width:22%; text-align:center;">
            <strong>Emisión:</strong> {{ $invoice->issue_date->format('d/m/Y') }}
        </td>
        <td style="width:22%; text-align:center;">
            <strong>Vence:</strong> {{ $invoice->due_date->format('d/m/Y') }}
        </td>
        <td style="width:18%; text-align:right;">
            <strong>Estado:</strong>
            <span style="background:{{ $statusColor }};color:#fff;padding:2px 8px;font-weight:bold;">{{ $statusEs }}</span>
        </td>
    </tr>
</table>

{{-- ══ EMISOR / RECEPTOR ══ --}}
<div class="info-wrap">
    <table width="100%">
        <tr>
            <td style="width:48%;" class="info-box">
                <div class="box-hd">Datos del Prestador del Servicio</div>
                <div class="info-row"><span class="lbl">Razón Social: </span><strong>{{ $companyName }}</strong></div>
                @if($nit)<div class="info-row"><span class="lbl">Identificación: </span>{{ $nit }}</div>@endif
                @if($taxRegime)<div class="info-row"><span class="lbl">Régimen fiscal: </span>{{ $taxRegime }}</div>@endif
                @if($address)<div class="info-row"><span class="lbl">Dirección: </span>{{ $address }}@if($city), {{ $city }}@endif@if($department) – {{ $department }}@endif</div>@endif
                @if($phone)<div class="info-row"><span class="lbl">Tel: </span>{{ $phone }}</div>@endif
                @if($email)<div class="info-row"><span class="lbl">Email: </span>{{ $email }}</div>@endif
            </td>
            <td style="width:4%;"></td>
            <td style="width:48%;" class="info-box">
                <div class="box-hd">Datos del Suscriptor / Cliente</div>
                <div class="info-row"><span class="lbl">Nombre: </span><strong>{{ $custName }}</strong></div>
                @if($custCedula)<div class="info-row"><span class="lbl">C.C. / NIT: </span>{{ $custCedula }}</div>@endif
                @if($customer->email)<div class="info-row"><span class="lbl">Email: </span>{{ $customer->email }}</div>@endif
                @if($custPhone)<div class="info-row"><span class="lbl">Tel: </span>{{ $custPhone }}</div>@endif
                @if($custAddress)<div class="info-row"><span class="lbl">Dirección: </span>{{ $custAddress }}@if($custCity), {{ $custCity }}@endif</div>@endif
            </td>
        </tr>
    </table>
</div>

{{-- ══ TABLA DE ÍTEMS ══ --}}
<div class="items-wrap">
    @if(strtolower($invoice->status) === 'paid')
        <div class="stamp">PAGADA</div>
    @endif

    <table width="100%">
        <thead class="it-hd">
            <tr>
                <th style="width:46%; text-align:left;">Descripción del Servicio</th>
                <th class="c" style="width:10%;">Cant.</th>
                <th class="r" style="width:16%;">Precio Unit.</th>
                <th class="r" style="width:12%;">IVA</th>
                <th class="r" style="width:16%;">Valor Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $i => $item)
                <tr class="it-row {{ $i % 2 === 1 ? 'it-alt' : '' }}">
                    <td>{{ $item->description }}</td>
                    <td class="c">{{ number_format($item->quantity, 2) }}@if($item->unit) {{ $item->unit }}@endif</td>
                    <td class="r">$ {{ number_format($item->unit_price, 2) }}</td>
                    <td class="r">$ 0.00</td>
                    <td class="r">$ {{ number_format($item->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- ══ TOTALES ══ --}}
<div class="tot-wrap">
    <table width="100%">
        <tr>
            <td style="vertical-align:top; padding-right:10px;">
                @if($invoice->notes)
                    <div style="border:1px solid #c5d9f0;padding:8px 10px;background:#fffef0;font-size:9px;color:#555;max-width:300px;">
                        <strong style="color:#003087;">Observaciones:</strong><br>{{ $invoice->notes }}
                    </div>
                @endif
                <div style="margin-top:10px;font-size:7.5px;color:#999;line-height:1.6;">
                    Empresa prestadora de servicios de telecomunicaciones.<br>
                    Habilitada por el Ministerio de Tecnologías de la Información y las Comunicaciones (MinTIC).
                    @if($nit)<br>{{ $nit }}@endif
                </div>
            </td>
            <td style="vertical-align:top; text-align:right;">
                <table class="tot-tbl">
                    <tr class="tot-sub">
                        <td>Subtotal</td>
                        <td>$ {{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->tax > 0)
                        <tr class="tot-tax">
                            <td>IVA (19%)</td>
                            <td>$ {{ number_format($invoice->tax, 2) }}</td>
                        </tr>
                    @else
                        <tr class="tot-tax">
                            <td style="color:#888;font-size:9px;">IVA (excluido / exento)</td>
                            <td style="color:#888;font-size:9px;">$ 0.00</td>
                        </tr>
                    @endif
                    <tr class="tot-grand">
                        <td>TOTAL A PAGAR</td>
                        <td>$ {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                    <tr class="tot-bal">
                        <td>Saldo Pendiente</td>
                        <td>$ {{ number_format($invoice->balance_due, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>

{{-- ══ PIE ══ --}}
<div class="footer">
    Documento generado electrónicamente &nbsp;·&nbsp; {{ $companyName }}
    @if($nit) &nbsp;·&nbsp; {{ $nit }} @endif
    &nbsp;·&nbsp; Moneda: {{ $invoice->currency ?? 'COP' }}
    <br>
    <span style="font-size:7px;color:#aaa;">
        Generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }}
        &nbsp;·&nbsp;
        Este documento es válido sin firma ni sello físico conforme a normativa DIAN.
    </span>
</div>

</body>
</html>
