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
| puede producir por sí solo. No incluye un bloque de totales (son
| placeholders escalares individuales, ya en document_placeholders.php).
|
| empresa.logo (auditoría 2026-08-03): agregado a los 3 tipos, incluido
| contrato — rompe deliberadamente la invariante previa de "contrato sin
| bloques" (la firma del cliente en contrato la sigue renderizando el shell
| fijo, fuera de body_html; el logo es un caso nuevo e independiente). Ver
| App\Services\Templates\BlockPlaceholderResolver::resolveLogo().
|
| NOTA: todavía no está conectada al selector de placeholders del frontend
| (DocumentTemplatesSection.vue solo lee document_placeholders.php hoy) —
| eso queda pendiente como trabajo aparte, no incluido en este alcance.
|
*/

return [

    'invoice' => [
        'factura.tabla_items' => 'Tabla de ítems de la factura (descripción, cantidad, precio, total)',
        'empresa.logo'        => 'Logo de la empresa',
    ],

    'contract' => [
        'empresa.logo'              => 'Logo de la empresa',
        'contrato.firma_cliente'    => 'Imagen de la firma del cliente',
        // Sólo se imprime si el contrato se firmó de forma remota por link;
        // en la firma presencial el bloque se resuelve a vacío.
        'contrato.constancia_firma' => 'Constancia de firma electrónica remota (fecha, IP, dispositivo)',
    ],

    // instalacion.fotos se retiró el 2026-08-05: las fotos de la instalación
    // se consultan en los documentos del cliente, y dentro del PDF nunca
    // llegaron a verse (ruta local vs. almacenamiento en S3 → imagen rota).
    'installation' => [
        'instalacion.firma_cliente'  => 'Imagen de la firma del cliente',
        'instalacion.firma_tecnico'  => 'Imagen de la firma del técnico',
        'empresa.logo'               => 'Logo de la empresa',
    ],

];
