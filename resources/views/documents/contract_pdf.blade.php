<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Contrato de Servicio</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; line-height: 1.6; }
        .header { text-align: center; border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; color: #4f46e5; }
        .header p { margin: 4px 0 0; font-size: 11px; color: #6b7280; }
        h2 { font-size: 13px; color: #4f46e5; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.info td { padding: 4px 6px; vertical-align: top; }
        table.info td.label { width: 32%; color: #6b7280; font-weight: bold; }
        .clause { margin: 8px 0; text-align: justify; }
        .sign-area { margin-top: 40px; width: 100%; }
        .sign-box { width: 45%; display: inline-block; text-align: center; }
        .sign-img { max-height: 90px; max-width: 220px; }
        .sign-line { border-top: 1px solid #111827; margin-top: 6px; padding-top: 6px; font-size: 11px; }
        .footer { margin-top: 30px; font-size: 10px; color: #9ca3af; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>

<body>
    @php
        $companyName = $tenant->legal_name ?: ($tenant->trade_name ?: $tenant->name ?? 'Proveedor de Internet');
        $cName = trim(($profile->name ?? $customer->user_name) . ' ' . ($profile->last_name ?? $customer->user_lastname));
    @endphp

    <div class="header">
        <h1>CONTRATO DE PRESTACIÓN DE SERVICIO DE INTERNET</h1>
        <p>{{ $companyName }}@if($tenant?->nit) — NIT {{ $tenant->nit }}@endif</p>
    </div>

    <p class="clause">
        Entre <strong>{{ $companyName }}</strong>, en adelante <strong>EL PROVEEDOR</strong>, y
        <strong>{{ $cName }}</strong>@if($profile?->cedula), identificado(a) con cédula No. {{ $profile->cedula }}@endif,
        en adelante <strong>EL CLIENTE</strong>, se celebra el presente contrato de prestación de servicio de
        acceso a Internet, sujeto a las siguientes condiciones:
    </p>

    <h2>1. Datos del Cliente</h2>
    <table class="info">
        <tr><td class="label">Nombre completo</td><td>{{ $cName }}</td></tr>
        <tr><td class="label">Cédula</td><td>{{ $profile->cedula ?? '—' }}</td></tr>
        <tr><td class="label">Correo electrónico</td><td>{{ $customer->email }}</td></tr>
        <tr><td class="label">Teléfono</td><td>{{ $customer->tel ?? '—' }}</td></tr>
        <tr><td class="label">Dirección</td><td>{{ $profile->address ?? '—' }} {{ $profile->city ?? '' }} {{ $profile->state ?? '' }}</td></tr>
        <tr><td class="label">IP asignada</td><td>{{ $profile->ip_user ?? '—' }}</td></tr>
    </table>

    <h2>2. Plan Contratado</h2>
    @if($plan)
        <table class="info">
            <tr><td class="label">Plan</td><td>{{ $plan->name }}</td></tr>
            <tr><td class="label">Velocidad de bajada</td><td>{{ $plan->speed_down ?? '—' }}</td></tr>
            <tr><td class="label">Velocidad de subida</td><td>{{ $plan->speed_up ?? '—' }}</td></tr>
            <tr><td class="label">Valor mensual</td><td>$ {{ number_format($plan->cost_product ?? 0, 0, ',', '.') }}</td></tr>
        </table>
    @else
        <p class="clause">El cliente no tiene un plan de servicio asignado al momento de la firma.</p>
    @endif

    <h2>3. Condiciones Generales</h2>
    <p class="clause">3.1. EL PROVEEDOR se compromete a prestar el servicio de acceso a Internet de acuerdo con el plan contratado, realizando su mejor esfuerzo para garantizar la continuidad del servicio.</p>
    <p class="clause">3.2. EL CLIENTE se compromete a pagar el valor mensual del plan dentro de las fechas establecidas. El incumplimiento en el pago podrá generar la suspensión del servicio.</p>
    <p class="clause">3.3. El equipo instalado es propiedad de EL PROVEEDOR salvo pacto en contrario, y deberá ser devuelto en buen estado a la terminación del contrato.</p>
    <p class="clause">3.4. EL CLIENTE autoriza el tratamiento de sus datos personales conforme a la ley aplicable, exclusivamente para fines relacionados con la prestación del servicio.</p>
    <p class="clause">3.5. El presente contrato tiene vigencia indefinida y podrá ser terminado por cualquiera de las partes con previo aviso.</p>

    <p class="clause" style="margin-top:16px;">
        En constancia de lo anterior, las partes firman el presente documento el día <strong>{{ $date }}</strong>.
    </p>

    <div class="sign-area">
        <div class="sign-box">
            <img class="sign-img" src="{{ $signature }}" alt="Firma del cliente">
            <div class="sign-line">
                <strong>{{ $cName }}</strong><br>
                C.C. {{ $profile->cedula ?? '—' }}<br>
                EL CLIENTE
            </div>
        </div>
        <div class="sign-box" style="float:right;">
            <div style="height:90px;"></div>
            <div class="sign-line">
                <strong>{{ $companyName }}</strong><br>
                @if($tenant?->nit) NIT {{ $tenant->nit }} @endif<br>
                EL PROVEEDOR
            </div>
        </div>
    </div>

    <div class="footer">
        Documento generado electrónicamente por ISPWatch — {{ $date }}
    </div>
</body>

</html>
