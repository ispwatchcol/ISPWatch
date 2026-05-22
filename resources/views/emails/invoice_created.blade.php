<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Factura</title>
</head>

<body
    style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7fa;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation"
                    style="width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);">

                    <!-- Header -->
                    <tr>
                        <td
                            style="padding: 40px 40px 30px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">
                                🧾 Nueva Factura Disponible
                            </h1>
                            <p style="margin: 10px 0 0; color: rgba(255, 255, 255, 0.9); font-size: 16px;">
                                {{ $companyName }}
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 20px; color: #374151; font-size: 16px; line-height: 1.6;">
                                Hola <strong>{{ $customerName }}</strong>,
                            </p>

                            <p style="margin: 0 0 20px; color: #374151; font-size: 16px; line-height: 1.6;">
                                Hemos generado tu factura del servicio @if($periodLabel)correspondiente a <strong>{{ $periodLabel }}</strong>@endif.
                                A continuación encontrarás los detalles:
                            </p>

                            <!-- Invoice Details Card -->
                            <table role="presentation"
                                style="width: 100%; border-collapse: collapse; margin: 25px 0; background-color: #f8fafc; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                            <tr>
                                                <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="color: #64748b; font-size: 14px;">Número de Factura</span>
                                                    <p
                                                        style="margin: 5px 0 0; color: #1e293b; font-size: 18px; font-weight: 600;">
                                                        #{{ $invoiceNumber }}</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="color: #64748b; font-size: 14px;">Monto a Pagar</span>
                                                    <p
                                                        style="margin: 5px 0 0; color: #10b981; font-size: 28px; font-weight: 700;">
                                                        ${{ number_format($amount, 0, ',', '.') }}</p>
                                                </td>
                                            </tr>
                                            @if($issueDate)
                                            <tr>
                                                <td style="padding: 10px 0; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="color: #64748b; font-size: 14px;">Fecha de Emisión</span>
                                                    <p
                                                        style="margin: 5px 0 0; color: #1e293b; font-size: 16px; font-weight: 500;">
                                                        {{ \Carbon\Carbon::parse($issueDate)->format('d M, Y') }}</p>
                                                </td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding: 10px 0;">
                                                    <span style="color: #64748b; font-size: 14px;">Fecha Límite de Pago</span>
                                                    <p
                                                        style="margin: 5px 0 0; color: #1e293b; font-size: 18px; font-weight: 600;">
                                                        {{ \Carbon\Carbon::parse($dueDate)->format('d M, Y') }}</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 25px 0; color: #374151; font-size: 16px; line-height: 1.6;">
                                Por favor realiza el pago antes de la fecha límite para evitar interrupciones en tu servicio.
                                Si ya tienes saldo a favor, se aplicará automáticamente.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0 0 10px; color: #64748b; font-size: 14px; text-align: center;">
                                ¿Tienes dudas? Contáctanos y con gusto te ayudaremos.
                            </p>
                            <p style="margin: 0; color: #94a3b8; font-size: 12px; text-align: center;">
                                Este correo fue enviado por {{ $companyName }} a través de ISPWatch.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
