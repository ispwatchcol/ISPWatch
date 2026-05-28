<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Hoja de Instalación</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; line-height: 1.5; }
        .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 18px; color: #2563eb; }
        .header p { margin: 4px 0 0; font-size: 11px; color: #6b7280; }
        h2 { font-size: 13px; color: #2563eb; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 14px 0 6px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.info td { padding: 3px 6px; vertical-align: top; }
        table.info td.label { width: 30%; color: #6b7280; font-weight: bold; }
        .photos { margin-top: 8px; }
        .photo { display: inline-block; width: 47%; vertical-align: top; margin: 0 1% 8px 0; text-align: center; }
        .photo img { max-width: 100%; max-height: 160px; border: 1px solid #e5e7eb; }
        .photo .cap { font-size: 9px; color: #6b7280; margin-top: 2px; word-break: break-all; }
        .sign-area { margin-top: 24px; width: 100%; }
        .sign-box { width: 45%; display: inline-block; text-align: center; vertical-align: top; }
        .sign-img { max-height: 80px; max-width: 220px; }
        .sign-line { border-top: 1px solid #111827; margin-top: 4px; padding-top: 4px; font-size: 11px; }
        .footer { margin-top: 20px; font-size: 9px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 6px; }
        .badge { display: inline-block; background: #dbeafe; color: #1e40af; font-size: 10px; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>

<body>
    @php
        $companyName = $tenant?->legal_name ?: ($tenant?->trade_name ?: $tenant?->name ?? 'Proveedor de Internet');
        $prospect = $prospect ?? null;
        $cName = $customer
            ? trim(($profile?->name ?? $customer?->user_name) . ' ' . ($profile?->last_name ?? $customer?->user_lastname))
            : trim(($prospect?->name ?? '') . ' ' . ($prospect?->last_name ?? ''));
        $cCedula  = $profile?->cedula ?? $prospect?->cedula;
        $cEmail   = $customer?->email ?? $prospect?->email;
        $cTel     = $customer?->tel ?? $prospect?->tel;
        $cAddress = $installation->address ?? $profile?->address ?? $prospect?->address;
        $techName = $technician
            ? trim(($technician->user_name ?? '') . ' ' . ($technician->user_lastname ?? '')) ?: $technician->name
            : ($installation->technician ?? '—');
        $sheet = $installation->sheet ?? [];
        $clientLabel = $customer ? 'Cliente' : 'Prospecto';
        $sectorial = $sectorial ?? null;
        $router    = $router ?? null;
        $plan      = $plan ?? null;
        $hasNetwork = $sectorial || $router || $plan
            || !empty($sheet['client_ip']) || !empty($sheet['pppoe_username'])
            || !empty($sheet['pppoe_local_address']) || !empty($sheet['vlan']);
    @endphp

    <div class="header">
        <h1>HOJA DE INSTALACIÓN</h1>
        <p>{{ $companyName }} @if($tenant?->nit) — NIT {{ $tenant->nit }}@endif</p>
        <p>Orden #{{ $installation->id }} · {{ $date }}</p>
    </div>

    <h2>{{ $clientLabel }}</h2>
    <table class="info">
        <tr><td class="label">Nombre</td><td>{{ $cName ?: '—' }}</td></tr>
        <tr><td class="label">Cédula</td><td>{{ $cCedula ?? '—' }}</td></tr>
        <tr><td class="label">Email</td><td>{{ $cEmail ?? '—' }}</td></tr>
        <tr><td class="label">Teléfono</td><td>{{ $cTel ?? '—' }}</td></tr>
        <tr><td class="label">Dirección</td><td>{{ $cAddress ?? '—' }}</td></tr>
    </table>

    <h2>Orden</h2>
    <table class="info">
        <tr><td class="label">Fecha programada</td><td>{{ $installation->scheduled_date?->format('d/m/Y') ?? '—' }}</td></tr>
        <tr><td class="label">Técnico</td><td>{{ $techName }}</td></tr>
        <tr><td class="label">Equipo / Materiales</td><td>{{ $installation->equipment ?? '—' }}</td></tr>
        <tr><td class="label">Observaciones</td><td>{{ $installation->notes ?? '—' }}</td></tr>
        <tr><td class="label">Estado</td><td><span class="badge">{{ strtoupper($installation->status) }}</span></td></tr>
    </table>

    @if($hasNetwork)
        @php
            $isPppoe = (bool) ($router?->pppoe);
            $localFromPlan = $plan?->local_address ?: $plan?->pppoe_pool;
            $effectiveLocal = !empty($sheet['local_address_manual']) && !empty($sheet['pppoe_local_address'])
                ? $sheet['pppoe_local_address']
                : $localFromPlan;
        @endphp
        <h2>Conexión / Red</h2>
        <table class="info">
            @if($sectorial)
                <tr><td class="label">{{ ucfirst($sectorial->element_type ?? 'sectorial') }}</td>
                    <td>{{ $sectorial->name }}@if($sectorial->ip) — {{ $sectorial->ip }}@endif</td></tr>
            @endif
            @if($router)
                <tr><td class="label">Core / Router</td>
                    <td>{{ $router->name }}@if($router->ip) — {{ $router->ip }}@endif
                        <span class="badge">{{ $isPppoe ? 'PPPoE' : 'IP estática / DHCP' }}</span></td></tr>
            @endif
            @if($plan)
                <tr><td class="label">Plan</td>
                    <td>{{ $plan->name }}@if($plan->speed_down) — {{ $plan->speed_down }}@if($plan->speed_up)/{{ $plan->speed_up }}@endif Mbps@endif</td></tr>
            @endif
            @if(!empty($sheet['vlan']))<tr><td class="label">VLAN</td><td>{{ $sheet['vlan'] }}</td></tr>@endif

            @if($isPppoe)
                @if(!empty($sheet['pppoe_username']))<tr><td class="label">Usuario PPPoE</td><td>{{ $sheet['pppoe_username'] }}</td></tr>@endif
                @if($effectiveLocal)
                    <tr><td class="label">IP local PPPoE</td>
                        <td>{{ $effectiveLocal }}
                            @if(empty($sheet['local_address_manual']) && $localFromPlan)
                                <span style="color:#6b7280; font-size:10px;">(tomada del plan)</span>
                            @endif
                        </td></tr>
                @endif
            @else
                @if(!empty($sheet['client_ip']))<tr><td class="label">IP del cliente</td><td>{{ $sheet['client_ip'] }}</td></tr>@endif
            @endif
        </table>
    @endif

    @if(!empty($sheet))
        <h2>Hoja Técnica</h2>
        <table class="info">
            @if(!empty($sheet['cable_meters']))<tr><td class="label">Cable utilizado</td><td>{{ $sheet['cable_meters'] }} m</td></tr>@endif
            @if(!empty($sheet['modem_brand']) || !empty($sheet['modem_model']))
                <tr><td class="label">Módem / Router</td><td>{{ $sheet['modem_brand'] ?? '' }} {{ $sheet['modem_model'] ?? '' }}</td></tr>
            @endif
            @if(!empty($sheet['modem_mac']))<tr><td class="label">MAC del módem</td><td>{{ $sheet['modem_mac'] }}</td></tr>@endif
            @if(!empty($sheet['onu_serial']))<tr><td class="label">Serial ONU</td><td>{{ $sheet['onu_serial'] }}</td></tr>@endif
            @if(!empty($sheet['signal_level']))<tr><td class="label">Nivel de señal</td><td>{{ $sheet['signal_level'] }}</td></tr>@endif
            @if(!empty($sheet['antenna_model']))<tr><td class="label">Antena</td><td>{{ $sheet['antenna_model'] }}</td></tr>@endif
            @if(!empty($sheet['materials']))<tr><td class="label">Materiales</td><td>{{ $sheet['materials'] }}</td></tr>@endif
            @if(!empty($sheet['observations']))<tr><td class="label">Observaciones técnicas</td><td>{{ $sheet['observations'] }}</td></tr>@endif
        </table>
    @endif

    @if($photos && count($photos))
        <h2>Fotos de la Instalación</h2>
        <div class="photos">
            @foreach($photos as $p)
                @if(in_array(strtolower(pathinfo($p->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp']))
                    <div class="photo">
                        <img src="{{ public_path('storage/' . $p->file_path) }}" alt="">
                        <div class="cap">{{ $p->file_name }}</div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    <div class="sign-area">
        <div class="sign-box">
            @if(!empty($customer_signature))
                <img class="sign-img" src="{{ $customer_signature }}" alt="Firma cliente">
            @endif
            <div class="sign-line">FIRMA DEL {{ strtoupper($clientLabel) }}<br>{{ $cName ?: '—' }}</div>
        </div>
        <div class="sign-box" style="float:right;">
            @if(!empty($technician_signature))
                <img class="sign-img" src="{{ $technician_signature }}" alt="Firma técnico">
            @endif
            <div class="sign-line">FIRMA DEL TÉCNICO<br>{{ $techName }}</div>
        </div>
    </div>

    <div class="footer">
        Documento generado el {{ $date }} por {{ $companyName }}.
    </div>
</body>

</html>
