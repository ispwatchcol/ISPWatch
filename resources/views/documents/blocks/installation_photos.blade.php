{{--
    Bloque de confianza (App\Services\Templates\BlockPlaceholderResolver::forInstallation).
    Mismo criterio de resolución de ruta/extensión que la vista legacy
    (resources/views/documents/installation_sheet_pdf.blade.php) — no se
    inventa un patrón de almacenamiento nuevo para el bloque.
--}}
@if($photos && count($photos))
    <div>
        @foreach($photos as $p)
            @if(in_array(strtolower(pathinfo($p->file_name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp']))
                <div style="display:inline-block; width:47%; vertical-align:top; margin:0 1% 8px 0; text-align:center;">
                    <img src="{{ public_path('storage/' . $p->file_path) }}" alt="" style="max-width:100%; max-height:160px; border:1px solid #e5e7eb;">
                    <div style="font-size:9px; color:#6b7280; margin-top:2px; word-break:break-all;">{{ $p->file_name }}</div>
                </div>
            @endif
        @endforeach
    </div>
@endif
