{{--
    Bloque de confianza (App\Services\Templates\BlockPlaceholderResolver::forInvoice).
    NUNCA pasa por App\Services\Templates\TemplateSanitizer — por eso puede usar
    atributos (colspan, style) que el allowlist del tenant no permite. Se inserta
    en la posición que el tenant eligió vía App\Services\Templates\BlockMarkerInjector,
    no aquí.
--}}
<table style="width:100%; border-collapse:collapse; font-size:9.5px;">
    <thead>
        <tr style="background:#f0f0f0;">
            <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #ccc;">Descripción</th>
            <th style="text-align:center; padding:6px 8px; border-bottom:1px solid #ccc;">Cant.</th>
            <th style="text-align:right; padding:6px 8px; border-bottom:1px solid #ccc;">Precio Unit.</th>
            <th style="text-align:right; padding:6px 8px; border-bottom:1px solid #ccc;">Valor Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($invoice->items as $item)
            <tr>
                <td style="padding:5px 8px; border-bottom:1px solid #e5e5e5;">{{ $item->description }}</td>
                <td style="text-align:center; padding:5px 8px; border-bottom:1px solid #e5e5e5;">
                    {{ number_format($item->quantity, 2) }}@if($item->unit) {{ $item->unit }}@endif
                </td>
                <td style="text-align:right; padding:5px 8px; border-bottom:1px solid #e5e5e5;">$ {{ number_format($item->unit_price, 2) }}</td>
                <td style="text-align:right; padding:5px 8px; border-bottom:1px solid #e5e5e5;">$ {{ number_format($item->amount, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
