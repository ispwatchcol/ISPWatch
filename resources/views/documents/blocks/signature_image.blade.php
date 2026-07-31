{{--
    Bloque de confianza reutilizado para instalacion.firma_cliente e
    instalacion.firma_tecnico (App\Services\Templates\BlockPlaceholderResolver).
    $signature ya es un data URI base64 (mismo formato que usa la vista legacy),
    nunca una URL remota — dompdf nunca hace un fetch externo por esto.
--}}
@if(!empty($signature))
    <img src="{{ $signature }}" alt="{{ $alt }}" style="max-height:80px; max-width:220px;">
@endif
