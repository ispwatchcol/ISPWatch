<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma tu contrato</title>
</head>

<body
    style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7fa;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation"
                    style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);">

                    <!-- Header -->
                    <tr>
                        <td
                            style="padding: 40px 40px 30px; background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%); text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 600;">
                                @if($isReminder) ⏳ Tu contrato sigue pendiente @else ✍️ Firma tu contrato @endif
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
                                @if($isReminder)
                                    Te recordamos que tu contrato de prestación del servicio de internet todavía está
                                    pendiente de firma. Puedes leerlo y firmarlo desde tu celular en menos de un minuto:
                                @else
                                    Ya puedes leer y firmar tu contrato de prestación del servicio de internet desde tu
                                    celular, sin instalar nada y sin crear ninguna cuenta:
                                @endif
                            </p>

                            <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $signingUrl }}"
                                            style="display: inline-block; padding: 16px 36px; background: #4f46e5; color: #ffffff; font-size: 17px; font-weight: 600; text-decoration: none; border-radius: 10px;">
                                            Leer y firmar mi contrato
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 20px; color: #6b7280; font-size: 14px; line-height: 1.6;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:<br>
                                <span style="color: #4f46e5; word-break: break-all;">{{ $signingUrl }}</span>
                            </p>

                            <table role="presentation"
                                style="width: 100%; border-collapse: collapse; margin: 25px 0; background-color: #fffbeb; border-radius: 10px; border: 1px solid #fde68a;">
                                <tr>
                                    <td style="padding: 16px 20px; color: #92400e; font-size: 14px; line-height: 1.6;">
                                        Este enlace es <strong>personal y de un solo uso</strong>, y vence el
                                        <strong>{{ $expiresAt }}</strong>. Por seguridad te pediremos los
                                        <strong>últimos 4 dígitos de tu cédula</strong> antes de mostrarte el contrato.
                                        No lo compartas con nadie.
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; color: #6b7280; font-size: 14px; line-height: 1.6;">
                                Si no reconoces esta solicitud, ignora este correo y comunícate con nosotros.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 40px; background-color: #f8fafc; text-align: center;">
                            <p style="margin: 0; color: #94a3b8; font-size: 12px;">
                                {{ $companyName }} — mensaje automático, por favor no respondas a este correo.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
