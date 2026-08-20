{{--
    Página que se muestra cuando la base de datos no responde.

    Deliberadamente sin nada: ni layout, ni assets compilados, ni sesión, ni
    consultas. Se sirve en el peor momento posible del sistema, así que no puede
    depender de ninguna pieza que también pueda estar caída. Todo el CSS va
    en línea por la misma razón.

    Lo que reemplaza es un `redirect()->back()` que, sin sesión, apuntaba a la
    raíz del sitio y producía un bucle infinito de redirecciones. Ver
    `app/Support/DatabaseFailure.php`.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Servicio no disponible — ISPWatch</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f6f6f4;
            color: #15191c;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
        }
        .card {
            max-width: 30rem;
            width: 100%;
            background: #ffffff;
            border: 1px solid #dbdcd6;
            border-radius: 4px;
            padding: 36px 32px;
        }
        .badge {
            display: inline-block;
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #845e00;
            background: #f6eeda;
            padding: .25em .6em;
            border-radius: 2px;
            margin-bottom: 18px;
        }
        h1 { font-size: 1.4rem; line-height: 1.3; margin: 0 0 12px; }
        p { margin: 0 0 14px; color: #3d464c; }
        p:last-of-type { margin-bottom: 0; }
        .actions { margin-top: 26px; }
        button {
            font: inherit;
            font-weight: 600;
            color: #ffffff;
            background: #2f6f8f;
            border: none;
            border-radius: 3px;
            padding: 10px 20px;
            cursor: pointer;
        }
        button:hover { background: #275c77; }
        button:focus-visible { outline: 2px solid #15191c; outline-offset: 2px; }
        .meta {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #dbdcd6;
            font-size: .82rem;
            color: #6a737a;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0e1215; color: #e6e9ea; }
            .card { background: #151a1e; border-color: #272f34; }
            p { color: #b3bbc0; }
            .badge { color: #d6a63f; background: #251e10; }
            .meta { border-top-color: #272f34; color: #828c93; }
            button { background: #78b4d4; color: #0e1215; }
            button:hover { background: #96c6df; }
            button:focus-visible { outline-color: #e6e9ea; }
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">Error 503</span>
        <h1>El servicio no está disponible en este momento</h1>
        <p>ISPWatch no puede acceder a sus datos ahora mismo. No es un problema de tu equipo ni de tu conexión, y no se ha perdido nada de lo que hayas guardado antes.</p>
        <p>El equipo técnico ya fue notificado automáticamente. Vuelve a intentarlo en unos minutos.</p>

        <div class="actions">
            <button type="button" onclick="window.location.reload()">Reintentar</button>
        </div>

        <p class="meta">Si el problema persiste más de quince minutos, avisa al administrador de tu empresa.</p>
    </main>
</body>
</html>
