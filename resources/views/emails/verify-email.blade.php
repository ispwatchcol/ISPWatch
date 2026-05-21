<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica tu correo - ISPWatch</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .email-wrapper {
            width: 100%;
            background-color: #f3f4f6;
            padding: 40px 0;
        }

        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .email-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            padding: 40px 30px;
            text-align: center;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px auto;
            background-color: #ffffff;
            border-radius: 50%;
            line-height: 80px;
            text-align: center;
            font-size: 26px;
            font-weight: 800;
            color: #3b82f6;
            letter-spacing: -1px;
        }

        .email-title {
            color: #ffffff;
            font-size: 26px;
            font-weight: 700;
            margin: 0;
            text-align: center;
        }

        .email-subtitle {
            color: #e0e7ff;
            font-size: 15px;
            margin: 8px 0 0 0;
            text-align: center;
        }

        .email-body {
            padding: 40px 36px;
        }

        .greeting {
            font-size: 20px;
            color: #1f2937;
            margin: 0 0 16px 0;
            font-weight: 600;
            text-align: center;
        }

        .message {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.7;
            margin: 0 0 28px 0;
            text-align: center;
        }

        .cta-container {
            text-align: center;
            margin: 28px 0;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff !important;
            text-decoration: none;
            padding: 16px 44px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
            letter-spacing: 0.3px;
        }

        .info-box {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 14px 16px;
            border-radius: 8px;
            margin: 28px 0;
            text-align: center;
        }

        .info-box p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
            line-height: 1.6;
        }

        .features {
            background-color: #f9fafb;
            padding: 28px 24px;
            border-radius: 12px;
            margin: 28px 0;
        }

        .features h3 {
            color: #1f2937;
            font-size: 17px;
            margin: 0 0 20px 0;
            font-weight: 600;
            text-align: center;
        }

        .feature-icon {
            width: 26px;
            height: 26px;
            background-color: #3b82f6;
            color: #ffffff;
            border-radius: 50%;
            line-height: 26px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
        }

        .feature-text {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
        }

        .footer {
            background-color: #f9fafb;
            padding: 28px 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }

        .footer-text {
            color: #9ca3af;
            font-size: 13px;
            margin: 0 0 8px 0;
        }

        .footer-link {
            color: #3b82f6;
            text-decoration: none;
            font-size: 12px;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        .ignore-text {
            font-size: 13px;
            color: #9ca3af;
            text-align: center;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="email-wrapper">
        <table width="100%" border="0" cellpadding="0" cellspacing="0">
            <tr>
                <td align="center" style="padding: 0 16px;">
                    <table class="email-container" width="600" border="0" cellpadding="0" cellspacing="0"
                        style="max-width:600px; width:100%; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 6px rgba(0,0,0,0.1);">

                        {{-- Header --}}
                        <tr>
                            <td style="background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%); padding:40px 30px; text-align:center;">
                                <div class="logo">ISP</div>
                                <h1 class="email-title">¡Bienvenido a ISPWatch!</h1>
                                <p class="email-subtitle">Tu plataforma de gestión de ISP</p>
                            </td>
                        </tr>

                        {{-- Body --}}
                        <tr>
                            <td style="padding: 40px 36px;">

                                <h2 class="greeting">¡Hola! 👋</h2>

                                <p class="message">
                                    Gracias por registrarte en <strong>ISPWatch</strong>. Para comenzar a usar tu cuenta
                                    y acceder a todas las funciones de nuestra plataforma, necesitamos verificar tu
                                    dirección de correo electrónico.
                                </p>

                                {{-- CTA Button --}}
                                <table width="100%" border="0" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center" style="padding: 8px 0 28px 0;">
                                            <a href="{{ $url }}" class="cta-button"
                                                style="display:inline-block; background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%); color:#ffffff; text-decoration:none; padding:16px 44px; border-radius:12px; font-size:16px; font-weight:600; box-shadow:0 4px 12px rgba(37,99,235,0.35);">
                                                ✓ &nbsp;Verificar mi correo electrónico
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                {{-- Info box --}}
                                <div class="info-box">
                                    <p>
                                        <strong>⏱️ Este enlace expirará en 60 minutos.</strong><br>
                                        Si no verificas tu cuenta dentro de este tiempo, tendrás que solicitar un nuevo
                                        enlace.
                                    </p>
                                </div>

                                {{-- Features --}}
                                <div class="features">
                                    <h3>🎉 ¿Qué incluye tu plan Trial?</h3>

                                    <table width="100%" border="0" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td width="38" valign="middle">
                                                <div class="feature-icon">✓</div>
                                            </td>
                                            <td valign="middle" style="padding-left: 8px; padding-bottom: 14px;">
                                                <p class="feature-text"><strong>30 clientes gratis</strong> — Gestiona
                                                    hasta 30 clientes sin costo</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td width="38" valign="middle">
                                                <div class="feature-icon">✓</div>
                                            </td>
                                            <td valign="middle" style="padding-left: 8px; padding-bottom: 14px;">
                                                <p class="feature-text"><strong>Gestión de routers MikroTik</strong> —
                                                    Configura y monitorea tus equipos</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td width="38" valign="middle">
                                                <div class="feature-icon">✓</div>
                                            </td>
                                            <td valign="middle" style="padding-left: 8px; padding-bottom: 14px;">
                                                <p class="feature-text"><strong>Control de pagos</strong> — Facturación
                                                    y gestión de pagos integrada</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td width="38" valign="middle">
                                                <div class="feature-icon">✓</div>
                                            </td>
                                            <td valign="middle" style="padding-left: 8px;">
                                                <p class="feature-text"><strong>Soporte técnico</strong> — Estamos aquí
                                                    para ayudarte</p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                <p class="message">
                                    Una vez verificada tu cuenta, podrás iniciar sesión y comenzar a configurar tu ISP.
                                </p>

                                <p class="ignore-text" style="margin-top: 8px;">
                                    Si no creaste una cuenta en ISPWatch, puedes ignorar este correo de forma segura.
                                </p>

                            </td>
                        </tr>

                        {{-- Footer --}}
                        <tr>
                            <td class="footer"
                                style="background-color:#f9fafb; padding:28px 30px; text-align:center; border-top:1px solid #e5e7eb;">
                                <p class="footer-text">
                                    <strong>ISPWatch</strong> — Sistema de Gestión para Proveedores de Internet
                                </p>
                                <p class="footer-text">© {{ date('Y') }} ISPWatch. Todos los derechos reservados.</p>
                                <p class="footer-text" style="margin-top: 12px;">
                                    ¿Necesitas ayuda?
                                    <a href="mailto:soporte@ispwatch.com" class="footer-link">Contáctanos</a>
                                </p>
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>
