<?php

/*
|--------------------------------------------------------------------------
| Equivalencias de marcadores de OTROS sistemas → marcadores de ISPwatch
|--------------------------------------------------------------------------
|
| Este archivo NO amplía lo que el sistema resuelve: PlaceholderResolver
| sigue reconociendo únicamente lo que está en document_placeholders.php y
| document_placeholder_blocks.php. Esto es un catálogo de DIAGNÓSTICO que
| usa App\Services\Templates\TemplateDiagnostics para poder decirle al
| tenant "ese marcador es de otro sistema, aquí se llama así" en vez de
| dejar que el dato salga en blanco sin ninguna pista.
|
| Por qué no se traducen automáticamente (auditoría 2026-08-06): traducir
| en silencio significaría que la plantilla guardada dice una cosa y el PDF
| imprime otra, y el tenant nunca aprendería el vocabulario real. Además,
| una equivalencia puede ser incorrecta en su caso concreto — 'fecha_
| instalacion' es la fecha de firma en un contrato pero la fecha de la orden
| en una hoja de instalación. Se avisa y se sugiere; reemplaza el humano.
|
| Origen de la tabla: contrato CRC exportado de WispHub que un tenant pegó
| en modo avanzado el 2026-08-05 (ver docs/BITACORA_TECNICA.md §15).
|
| Estructura:
|   'scalar'  => marcador ajeno con llaves ({{...}}) => token de ISPwatch.
|   'literal' => marcador ajeno SIN llaves, que en ISPwatch es texto
|                literal y se imprime tal cual (WispHub los sustituye por
|                posición, no por sintaxis) => token de ISPwatch.
|
| La clave 'common' aplica a los 3 tipos de documento; la clave del tipo
| tiene prioridad sobre 'common' cuando el mismo alias existe en las dos.
|
*/

return [

    'scalar' => [

        'common' => [
            'cliente_nombre'       => 'cliente.nombre',
            'cliente_apellidos'    => 'cliente.apellido',
            'cliente_apellido'     => 'cliente.apellido',
            'cliente.user.email'   => 'cliente.email',
            'cliente.user.name'    => 'cliente.nombre',
            'cliente.correo'       => 'cliente.email',
            'cliente.localidad'    => 'cliente.departamento',
            'empresa.razon_social' => 'empresa.nombre',
        ],

        'invoice' => [
            'factura.fecha'  => 'factura.fecha_emision',
            'factura.numero_factura' => 'factura.numero',
        ],

        'contract' => [
            // En un contrato, la "fecha de instalación" de WispHub es la
            // fecha desde la que corre la vigencia, o sea la de la firma.
            'fecha_instalacion'             => 'contrato.fecha',
            'plan_internet.nombre'          => 'plan.nombre',
            'plan_internet.precio'          => 'plan.valor_mensual',
            'plan_internet.velocidad_bajada' => 'plan.velocidad_bajada',
            'plan_internet.velocidad_subida' => 'plan.velocidad_subida',
            'numero_contrato'               => 'contrato.numero',
            'contrato.consecutivo'          => 'contrato.numero',
        ],

        'installation' => [
            // Aquí sí es la fecha de la orden, no la de un contrato.
            'fecha_instalacion'    => 'instalacion.fecha',
            'plan_internet.nombre' => 'servicio.plan',
            'tecnico'              => 'instalacion.tecnico',
        ],

    ],

    'literal' => [

        'common' => [],

        'invoice' => [],

        'contract' => [
            'NUMERO_CONTRATO_TAG'     => 'contrato.numero',
            'FIRMA_CLIENTE_NO_BORRAR' => 'contrato.firma_cliente',
        ],

        'installation' => [
            'FIRMA_CLIENTE_NO_BORRAR' => 'instalacion.firma_cliente',
            'FIRMA_TECNICO_NO_BORRAR' => 'instalacion.firma_tecnico',
        ],

    ],

];
