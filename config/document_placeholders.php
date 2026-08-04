<?php

/*
|--------------------------------------------------------------------------
| Whitelist de placeholders para plantillas de documentos
|--------------------------------------------------------------------------
|
| Única fuente de verdad para los tokens {{namespace.campo}} que un tenant
| puede usar al editar su plantilla de cada tipo de documento. Debe usarse
| tanto por App\Services\Templates\PlaceholderResolver (para saber qué
| resolver) como, en fases posteriores, por el selector de placeholders del
| frontend (vía un endpoint que exponga este mismo array).
|
| Cualquier token que no aparezca aquí para el tipo correspondiente se
| considera "desconocido" y App\Services\Templates\PlaceholderResolver::apply()
| lo reemplaza por una cadena vacía en vez de fallar.
|
*/

return [

    'invoice' => [
        'empresa.nombre'            => 'Nombre legal de la empresa',
        'empresa.nombre_comercial'  => 'Nombre comercial de la empresa',
        'empresa.nit'               => 'NIT (con dígito de verificación)',
        'empresa.direccion'         => 'Dirección de facturación',
        'empresa.telefono'          => 'Teléfono de facturación',
        'empresa.email'             => 'Correo de facturación',
        'empresa.ciudad'            => 'Ciudad de la empresa',
        'cliente.nombre'            => 'Nombre(s) del cliente',
        'cliente.apellido'          => 'Apellido(s) del cliente',
        'cliente.cedula'            => 'Cédula / NIT del cliente',
        'cliente.direccion'         => 'Dirección del cliente',
        'cliente.email'             => 'Correo del cliente',
        'cliente.telefono'          => 'Teléfono del cliente',
        'factura.numero'            => 'Número de factura',
        'factura.fecha_emision'     => 'Fecha de emisión',
        'factura.fecha_vencimiento' => 'Fecha de vencimiento',
        'factura.periodo'           => 'Período facturado',
        'factura.subtotal'          => 'Subtotal',
        'factura.impuestos'         => 'Impuestos',
        'factura.total'             => 'Total a pagar',
        'factura.saldo'             => 'Saldo pendiente',
        'factura.estado'            => 'Estado de la factura',
        'factura.notas'             => 'Notas / observaciones de la factura',
    ],

    'contract' => [
        'contrato.numero'        => 'Número consecutivo del contrato',
        'empresa.nombre'         => 'Nombre legal de la empresa',
        'empresa.nit'            => 'NIT (con dígito de verificación)',
        'cliente.nombre'         => 'Nombre(s) del cliente',
        'cliente.apellido'       => 'Apellido(s) del cliente',
        'cliente.cedula'         => 'Cédula del cliente',
        'cliente.direccion'      => 'Dirección del cliente',
        'cliente.email'          => 'Correo del cliente',
        'cliente.telefono'       => 'Teléfono del cliente',
        'cliente.ip'             => 'IP asignada al cliente',
        'plan.nombre'            => 'Nombre del plan contratado',
        'plan.velocidad_bajada'  => 'Velocidad de bajada',
        'plan.velocidad_subida'  => 'Velocidad de subida',
        'plan.valor_mensual'     => 'Valor mensual del plan',
        'contrato.fecha'         => 'Fecha de firma del contrato',
    ],

    'installation' => [
        'empresa.nombre'             => 'Nombre legal de la empresa',
        'cliente.nombre'             => 'Nombre(s) del cliente / prospecto',
        'cliente.apellido'           => 'Apellido(s) del cliente / prospecto',
        'cliente.cedula'             => 'Cédula del cliente / prospecto',
        'cliente.direccion'          => 'Dirección de la instalación',
        'instalacion.numero'         => 'Número / ID de la orden',
        'instalacion.fecha'          => 'Fecha de la instalación',
        'instalacion.tecnico'        => 'Técnico asignado',
        'instalacion.equipo'         => 'Equipo / materiales usados',
        'instalacion.observaciones'  => 'Observaciones de la orden',
        'servicio.plan'              => 'Nombre del plan contratado',
        'servicio.velocidad_bajada'  => 'Velocidad de bajada del plan',
        'servicio.velocidad_subida'  => 'Velocidad de subida del plan',
        'servicio.ip'                => 'IP asignada al servicio',
        'servicio.usuario_pppoe'     => 'Usuario PPPoE del servicio',
        'servicio.punto_acceso'      => 'Sector / punto de acceso (AP) asignado',
        'servicio.dia_pago'          => 'Día de pago mensual (según el router del cliente)',
        'servicio.dia_corte'         => 'Día de corte por mora (según el router del cliente)',
    ],

];
