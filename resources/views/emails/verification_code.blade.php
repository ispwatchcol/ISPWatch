<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .code {
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 5px;
            color: #2563eb;
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
        }

        .footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 30px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Verificación de Correo</h2>
        </div>
        <p>Hola,</p>
        <p>Utiliza el siguiente código para completar tu registro en ISPWatch:</p>

        <div class="code">{{ $code }}</div>

        <p>Este código expira en 10 minutos.</p>
        <p>Si no has solicitado este código, puedes ignorar este correo.</p>

        <div class="footer">
            &copy; {{ date('Y') }} ISPWatch. Todos los derechos reservados.
        </div>
    </div>
</body>

</html>