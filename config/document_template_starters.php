<?php

/*
|--------------------------------------------------------------------------
| Plantillas base seleccionables para el editor de documentos
|--------------------------------------------------------------------------
|
| El editor arrancaba en blanco: un tenant que abría "Plantillas de
| Documentos" se encontraba un lienzo vacío y ninguna pista de por dónde
| empezar, aunque el sistema SÍ tiene un formato base (el que usa cuando no
| hay plantilla personalizada, en resources/views/documents/). Estas son
| versiones editables de ese formato más los formatos regulados que un ISP
| colombiano necesita, para que el punto de partida sea un documento
| completo y no una hoja en blanco.
|
| Los cuerpos viven en resources/document-starters/{tipo}/{slug}.html como
| HTML PLANO, no como vistas Blade: Blade interpretaría los {{marcador}} como
| expresiones PHP y reventaría al renderizar. Se leen con file_get_contents,
| nunca se compilan.
|
| `advanced` fija el modo con el que la plantilla tiene sentido: un documento
| completo con <style> y tablas necesita modo avanzado, porque el allowlist
| del modo seguro (TemplateSanitizer) los descarta al guardar.
|
| AVISO LEGAL: los formatos regulados son un punto de partida con la
| ESTRUCTURA que exige la norma, no asesoría jurídica ni una certificación de
| cumplimiento. Cada operador debe revisarlos y completarlos con sus propias
| condiciones antes de usarlos con clientes reales.
|
*/

return [

    'invoice' => [
        [
            'slug'             => 'ispwatch-basica',
            'name'             => 'Factura básica',
            'description'      => 'El formato que usa el sistema por defecto, ya editable: datos de empresa y cliente, tabla de ítems y totales.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
    ],

    'contract' => [
        [
            'slug'             => 'ispwatch-basico',
            'name'             => 'Genérico · Contrato básico',
            'description'      => 'El contrato que usa el sistema por defecto, ya editable: datos del cliente, plan, condiciones generales y firma. Sin formato regulado de ningún país.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
        [
            'slug'             => 'crc-colombia',
            'name'             => 'Colombia · Contrato único CRC',
            'description'      => 'Formato regulado a dos columnas de la CRC/MinTIC para servicios fijos. Se abre en horizontal, que es lo único en lo que cabe.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'landscape',
        ],
        [
            'slug'             => 'ift-mexico',
            'name'             => 'México · Contrato de adhesión (IFT)',
            'description'      => 'Estructura de la Ley Federal de Telecomunicaciones: características del servicio, velocidad mínima garantizada y Carta de Derechos del IFT. Recuerda registrarlo ante PROFECO.',
            'advanced'         => true,
            'page_size'        => 'letter',
            'page_orientation' => 'portrait',
        ],
        [
            'slug'             => 'enacom-argentina',
            'name'             => 'Argentina · Servicios TIC (ENACOM)',
            'description'      => 'Estructura de la Ley 27.078: baja por el mismo medio de contratación, bonificación automática por interrupciones y aviso previo de 30 días para cambios.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
        [
            'slug'             => 'osiptel-peru',
            'name'             => 'Perú · Contrato de abonado (OSIPTEL)',
            'description'      => 'Estructura de las Condiciones de Uso del OSIPTEL: velocidad mínima garantizada del 40 %, suspensión a solicitud y apelación ante el TRASU.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
        [
            'slug'             => 'subtel-chile',
            'name'             => 'Chile · Suministro de internet (SUBTEL)',
            'description'      => 'Estructura del Reglamento de Servicios de Telecomunicaciones: velocidad promedio garantizada, descuento de oficio por indisponibilidad y término por el mismo medio.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
        [
            'slug'             => 'att-bolivia',
            'name'             => 'Bolivia · Prestación de internet (ATT)',
            'description'      => 'Estructura de la Ley N° 164: derechos del usuario, indicadores de calidad de la ATT y compensación por interrupciones.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
    ],

    'installation' => [
        [
            'slug'             => 'ispwatch-basica',
            'name'             => 'Acta de instalación básica',
            'description'      => 'El acta que usa el sistema por defecto, ya editable: orden, cliente, servicio, equipos y firmas.',
            'advanced'         => true,
            'page_size'        => 'a4',
            'page_orientation' => 'portrait',
        ],
    ],

];
