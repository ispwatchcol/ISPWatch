{{--
    Bloque de confianza para empresa.logo (App\Services\Templates\BlockPlaceholderResolver).
    Misma resolución que ya usa documents/shells/invoice_shell.blade.php: ruta
    LOCAL en disco (public_path('storage/'.$tenant->logo)), nunca una URL —
    dompdf nunca hace un fetch de red por esto, es inmune a enable_remote.
    file_exists() degrada a nada si el tenant no subió logo o falta el
    symlink storage:link, igual que el resto de bloques de imagen.
--}}
@if(!empty($logoPath) && file_exists($logoPath))
    <img src="{{ $logoPath }}" alt="Logo" style="max-height:80px; max-width:220px;">
@endif
