<?php

/*
|--------------------------------------------------------------------------
| Whitelist de placeholders de BLOQUE para plantillas de documentos
|--------------------------------------------------------------------------
|
| Paralela a document_placeholders.php (que cubre los placeholders
| escalares). Un placeholder de bloque no sustituye texto: sustituye un
| fragmento HTML de confianza pre-renderizado por el servidor (tabla de
| ítems, galería de fotos, imagen de firma) — ver
| App\Services\Templates\BlockPlaceholderResolver y
| App\Services\Templates\BlockMarkerInjector.
|
| Alcance V1 (auditoría 2026-07-31): solo los 4 casos que de verdad
| necesitan loop o <img>, que el allowlist del tenant (TemplateSanitizer) no
| puede producir por sí solo. No incluye contrato (la firma la sigue
| renderizando el shell fijo, fuera de body_html) ni un bloque de totales
| (son placeholders escalares individuales, ya en document_placeholders.php).
|
| NOTA: todavía no está conectada al selector de placeholders del frontend
| (DocumentTemplatesSection.vue solo lee document_placeholders.php hoy) —
| eso queda pendiente como trabajo aparte, no incluido en este alcance.
|
*/

return [

    'invoice' => [
        'factura.tabla_items' => 'Tabla de ítems de la factura (descripción, cantidad, precio, total)',
    ],

    'contract' => [
        // Sin bloques en V1.
    ],

    'installation' => [
        'instalacion.fotos'          => 'Galería de fotos de la instalación',
        'instalacion.firma_cliente'  => 'Imagen de la firma del cliente',
        'instalacion.firma_tecnico'  => 'Imagen de la firma del técnico',
    ],

];
