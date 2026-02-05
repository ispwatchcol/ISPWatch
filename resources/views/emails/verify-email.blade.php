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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f3f4f6;
        }

        .email-container {
            max-width: 600px;
            margin: 40px auto;
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
            margin: 0 auto 20px;
            background-color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: bold;
            color: #3b82f6;
        }

        .email-title {
            color: #ffffff;
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .email-subtitle {
            color: #e0e7ff;
            font-size: 16px;
            margin: 10px 0 0 0;
        }

        .email-body {
            padding: 40px 30px;
        }

        .greeting {
            font-size: 20px;
            color: #1f2937;
            margin: 0 0 20px 0;
            font-weight: 600;
        }

        .message {
            font-size: 16px;
            color: #4b5563;
            line-height: 1.6;
            margin: 0 0 30px 0;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 16px 40px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.3);
            transition: transform 0.2s;
        }

        .cta-button:hover {
            transform: translateY(-2px);
        }

        .cta-container {
            text-align: center;
            margin: 30px 0;
        }

        .info-box {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 8px;
            margin: 30px 0;
        }

        .info-box p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
            line-height: 1.5;
        }

        .features {
            background-color: #f9fafb;
            padding: 30px;
            border-radius: 12px;
            margin: 30px 0;
        }

        .features h3 {
            color: #1f2937;
            font-size: 18px;
            margin: 0 0 20px 0;
            font-weight: 600;
        }

        .feature-item {
            display: flex;
            align-items: start;
            margin-bottom: 16px;
        }

        .feature-icon {
            background-color: #3b82f6;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
            font-size: 14px;
        }

        .feature-text {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
            margin: 0;
        }

        .footer {
            background-color: #f9fafb;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }

        .footer-text {
            color: #9ca3af;
            font-size: 14px;
            margin: 0 0 10px 0;
        }

        .footer-link {
            color: #3b82f6;
            text-decoration: none;
            font-size: 12px;
        }

        .footer-link:hover {
            text-decoration: underline;
        }

        .backup-url {
            margin: 30px 0;
            padding: 20px;
            background-color: #f9fafb;
            border-radius: 8px;
        }

        .backup-url p {
            color: #6b7280;
            font-size: 12px;
            margin: 0 0 10px 0;
            line-height: 1.5;
        }

        .backup-url code {
            display: block;
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            padding: 12px;
            border-radius: 6px;
            font-size: 11px;
            color: #374151;
            word-break: break-all;
            margin-top: 8px;
        }
    </style>
</head>

<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <div class="logo">ISP</div>
            <h1 class="email-title">¡Bienvenido a ISPWatch!</h1>
            <p class="email-subtitle">Tu plataforma de gestión de ISP</p>
        </div>

        <!-- Body -->
        <div class="email-body">
            <h2 class="greeting">¡Hola! 👋</h2>

            <p class="message">
                Gracias por registrarte en <strong>ISPWatch</strong>. Para comenzar a usar tu cuenta y acceder a todas
                las funciones de nuestra plataforma, necesitamos verificar tu dirección de correo electrónico.
            </p>

            <div class="cta-container">
                <a href="{{ $url }}" class="cta-button">
                    ✓ Verificar mi correo electrónico
                </a>
            </div>

            <div class="info-box">
                <p>
                    <strong>⏱️ Este enlace expirará en 60 minutos.</strong><br>
                    Si no verificas tu cuenta dentro de este tiempo, tendrás que solicitar un nuevo enlace.
                </p>
            </div>

            <div class="features">
                <h3>🎉 ¿Qué incluye tu plan Trial?</h3>
                <div class="feature-item">
                    <div class="feature-icon">✓</div>
                    <p class="feature-text"><strong>30 clientes gratis</strong> - Gestiona hasta 30 clientes sin costo
                    </p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">✓</div>
                    <p class="feature-text"><strong>Gestión de routers MikroTik</strong> - Configura y monitorea tus
                        equipos</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">✓</div>
                    <p class="feature-text"><strong>Control de pagos</strong> - Facturación y gestión de pagos</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">✓</div>
                    <p class="feature-text"><strong>Soporte técnico</strong> - Estamos aquí para ayudarte</p>
                </div>
            </div>

            <p class="message">
                Una vez verificada tu cuenta, podrás iniciar sesión y comenzar a configurar tu ISP.
            </p>

            <div class="backup-url">
                <p>
                    Si el botón no funciona, copia y pega este enlace en tu navegador:
                </p>
                <code>{{ $url }}</code>
            </div>

            <p class="message" style="margin-top: 30px; font-size: 14px; color: #6b7280;">
                Si no creaste una cuenta en ISPWatch, puedes ignorar este correo de forma segura.
            </p>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">
                <strong>ISPWatch</strong> - Sistema de Gestión para Proveedores de Internet
            </p>
            <p class="footer-text">
                © {{ date('Y') }} ISPWatch. Todos los derechos reservados.
            </p>
            <p class="footer-text" style="margin-top: 15px;">
                ¿Necesitas ayuda? <a href="mailto:soporte@ispwatch.com" class="footer-link">Contáctanos</a>
            </p>
        </div>
    </div>
</body>

</html>