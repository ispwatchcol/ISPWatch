<?php
namespace App\Http\Controllers;

use App\Exports\CustomersUpdateTemplateExport;
use App\Exports\ImportErrorsExport;
use App\Exports\InventoryTemplateExport;
use App\Exports\UnifiedTemplateExport;
use App\Imports\CustomersUpdateImport;
use App\Imports\InventoryImport;
use App\Imports\UnifiedImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function downloadUnifiedTemplate()
    {
        return Excel::download(new UnifiedTemplateExport(), 'plantilla_carga_completa.xlsx');
    }

    public function importUnified(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:10240',
        ]);

        // Cargas masivas grandes pueden superar el max_execution_time por defecto (60s)
        @set_time_limit(120);

        $tenantId = auth()->user()->tenant_id;
        $import = new UnifiedImport($tenantId);

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                'summary' => $import->summary(),
                'errors' => $import->allErrors(),
            ], 500);
        }

        $errors = $import->allErrors();
        $summary = $import->summary();
        $hasAnyImport = array_sum($summary) > 0;
        $hasErrors = !empty($errors);

        return response()->json([
            'success' => !$hasErrors,
            'partial' => $hasErrors && $hasAnyImport,
            'summary' => $summary,
            'errors' => $errors,
            'message' => $this->buildSummaryMessage($summary, $errors),
        ], $hasErrors && !$hasAnyImport ? 422 : 200);
    }

    public function downloadCustomersUpdateTemplate()
    {
        return Excel::download(new CustomersUpdateTemplateExport(), 'plantilla_actualizacion_clientes.xlsx');
    }

    public function importCustomersUpdate(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:10240',
        ]);

        // Cargas grandes pueden tardar; evitamos que PHP corte la ejecución.
        // (El 504 venía del gateway por el costo por-fila, ya reducido en el import.)
        @set_time_limit(0);
        @ignore_user_abort(true);

        $tenantId = auth()->user()->tenant_id;
        $import = new CustomersUpdateImport($tenantId);

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                'summary' => ['clientes' => $import->updated],
                'errors' => $import->errors,
            ], 500);
        }

        $errors = $import->errors;
        $updated = $import->updated;
        $hasErrors = !empty($errors);

        return response()->json([
            'success' => !$hasErrors,
            'partial' => $hasErrors && $updated > 0,
            'summary' => ['clientes' => $updated],
            'errors' => $errors,
            'message' => $this->buildUpdateSummaryMessage($updated, $errors),
        ], $hasErrors && $updated === 0 ? 422 : 200);
    }

    public function downloadInventoryTemplate()
    {
        return Excel::download(new InventoryTemplateExport(), 'plantilla_inventario.xlsx');
    }

    public function importInventory(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:10240',
        ]);

        // Cargas grandes pueden superar el max_execution_time por defecto.
        @set_time_limit(120);

        $tenantId = auth()->user()->tenant_id;
        $import = new InventoryImport($tenantId);

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
                'summary' => ['equipos' => $import->imported],
                'errors'  => $import->errors,
            ], 500);
        }

        $errors = $import->errors;
        $imported = $import->imported;
        $hasErrors = !empty($errors);

        return response()->json([
            'success' => !$hasErrors,
            'partial' => $hasErrors && $imported > 0,
            'summary' => ['equipos' => $imported],
            'errors'  => $errors,
            'message' => $this->buildInventorySummaryMessage($imported, $errors),
        ], $hasErrors && $imported === 0 ? 422 : 200);
    }

    public function inventoryFieldDocs()
    {
        return response()->json([
            'Inventario' => [
                ['field' => 'marca', 'required' => false, 'description' => 'Marca del equipo. Junto con el modelo define el ítem de stock; si no existe se crea automáticamente.', 'example' => 'TP-Link'],
                ['field' => 'modelo', 'required' => false, 'description' => 'Modelo del equipo. Junto con la marca define el ítem de stock. Indica al menos marca, modelo, serial o MAC.', 'example' => 'Archer C6'],
                ['field' => 'precio', 'required' => false, 'description' => 'Precio del equipo (solo números). Se guarda en el ítem de stock la primera vez que aparece la marca-modelo.', 'example' => '120000'],
                ['field' => 'serial', 'required' => false, 'description' => 'Número de serie único del equipo. Si ya existe en el inventario, la fila se rechaza.', 'example' => 'SN-0001'],
                ['field' => 'mac', 'required' => false, 'description' => 'Dirección MAC única del equipo. Si ya existe en el inventario, la fila se rechaza.', 'example' => 'AA:BB:CC:DD:EE:01'],
                ['field' => 'proveedor', 'required' => false, 'description' => 'Nombre del proveedor. Si no existe se crea automáticamente.', 'example' => 'Proveedor Principal'],
                ['field' => 'sucursal', 'required' => false, 'description' => 'Nombre de la sucursal/bodega. Si no existe se crea automáticamente.', 'example' => 'Bodega Central'],
            ],
        ]);
    }

    private function buildInventorySummaryMessage(int $imported, array $errors): string
    {
        if ($imported === 0 && empty($errors)) {
            return 'No se encontraron filas para importar.';
        }

        $msg = $imported > 0
            ? "Importado(s): {$imported} equipo(s)"
            : 'Ningún equipo importado';

        if (!empty($errors)) {
            $msg .= '. Se encontraron ' . count($errors) . ' errores.';
        }

        return $msg;
    }

    public function customersUpdateFieldDocs()
    {
        return response()->json([
            'Clientes' => [
                ['field' => 'email_actual', 'required' => false, 'description' => 'Email actual del cliente — llave principal para identificarlo. NO se modifica salvo que indiques nuevo_email. Si lo dejas vacío o no existe, se usa la cédula para identificar al cliente.', 'example' => 'juan@mail.com'],
                ['field' => 'nuevo_email', 'required' => false, 'description' => 'Nuevo email del cliente. Debe ser único en el tenant.', 'example' => 'juan.nuevo@mail.com'],
                ['field' => 'nombre', 'required' => false, 'description' => 'Nuevo nombre del cliente. Deja vacío para no cambiar.', 'example' => 'Juan'],
                ['field' => 'apellido', 'required' => false, 'description' => 'Nuevo apellido. Deja vacío para no cambiar.', 'example' => 'Pérez'],
                ['field' => 'cedula', 'required' => false, 'description' => 'Cédula / documento de identidad. Sirve como identificador alterno cuando no hay email_actual (debe ser única; si dos clientes la comparten se pedirá usar email_actual).', 'example' => '1010101010'],
                ['field' => 'telefono', 'required' => false, 'description' => 'Teléfono de contacto.', 'example' => '3001234567'],
                ['field' => 'direccion', 'required' => false, 'description' => 'Dirección física.', 'example' => 'Calle 1 #2-3'],
                ['field' => 'ciudad', 'required' => false, 'description' => 'Ciudad.', 'example' => 'Bogotá'],
                ['field' => 'ip_usuario', 'required' => false, 'description' => 'Nueva IP del cliente. Debe ser única entre clientes del tenant.', 'example' => '10.0.0.5'],
                ['field' => 'ip_router', 'required' => false, 'description' => 'IP del router al que se quiere mover al cliente. Debe existir.', 'example' => '192.168.1.1'],
                ['field' => 'nombre_plan', 'required' => false, 'description' => 'Nombre del plan al que se quiere cambiar. Debe existir.', 'example' => 'Internet 10MB'],
                ['field' => 'nombre_sectorial', 'required' => false, 'description' => 'Nombre de la sectorial destino. Debe existir.', 'example' => 'Sectorial Norte'],
                ['field' => 'usuario_pppoe', 'required' => false, 'description' => 'Usuario PPPoE.', 'example' => 'juan.perez'],
                ['field' => 'password_pppoe', 'required' => false, 'description' => 'Contraseña PPPoE.', 'example' => 'secret123'],
                ['field' => 'password', 'required' => false, 'description' => 'Nueva contraseña de acceso del cliente (mínimo 6 caracteres).', 'example' => 'NuevaPass123'],
                ['field' => 'fecha_instalacion', 'required' => false, 'description' => 'Fecha de instalación del servicio (formato AAAA-MM-DD). Deja vacío para no cambiar.', 'example' => '2026-05-28'],
                ['field' => 'estrato', 'required' => false, 'description' => 'Estrato socioeconómico (número del 1 al 6). Deja vacío para no cambiar.', 'example' => '3'],
            ],
        ]);
    }

    private function buildUpdateSummaryMessage(int $updated, array $errors): string
    {
        if ($updated === 0 && empty($errors)) {
            return 'No se encontraron filas para actualizar.';
        }

        $msg = $updated > 0
            ? "Actualizado(s): {$updated} cliente(s)"
            : 'Ningún cliente actualizado';

        if (!empty($errors)) {
            $msg .= '. Se encontraron ' . count($errors) . ' errores.';
        }

        return $msg;
    }

    public function exportErrors(Request $request)
    {
        $data = $request->validate([
            'errors' => 'required|array|min:1',
            'errors.*.sheet' => 'nullable|string',
            'errors.*.row' => 'nullable',
            'errors.*.field' => 'nullable|string',
            'errors.*.error' => 'nullable|string',
        ]);

        return Excel::download(new ImportErrorsExport($data['errors']), 'errores_carga_masiva.xlsx');
    }

    public function fieldDocs()
    {
        return response()->json([
            'Routers' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre descriptivo del router', 'example' => 'Torre Centro'],
                ['field' => 'ip', 'required' => true, 'description' => 'Dirección IP única del router', 'example' => '192.168.1.1'],
                ['field' => 'usuario', 'required' => true, 'description' => 'Usuario de acceso RouterOS', 'example' => 'admin'],
                ['field' => 'password', 'required' => true, 'description' => 'Contraseña de acceso', 'example' => 'password123'],
                ['field' => 'tipo_corte', 'required' => true, 'description' => 'Corte Automático, Corte Manual o Sin Corte', 'example' => 'Corte Automático'],
                ['field' => 'pppoe', 'required' => false, 'description' => '¿El core es PPPoE? Sí/No (también acepta 1/0, true/false)', 'example' => 'Sí'],
                ['field' => 'puerto', 'required' => false, 'description' => 'Puerto API MikroTik (default: 8728)', 'example' => '8728'],
                ['field' => 'wan_interface', 'required' => false, 'description' => 'Interfaz WAN (default: ether1)', 'example' => 'ether1'],
            ],
            'Sectoriales' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre de la sectorial', 'example' => 'Sectorial Norte'],
                ['field' => 'tipo', 'required' => false, 'description' => 'Tipo de antena (omni/panel/etc)', 'example' => 'omni'],
                ['field' => 'ip', 'required' => false, 'description' => 'IP de la sectorial', 'example' => '192.168.10.1'],
                ['field' => 'usuario', 'required' => false, 'description' => 'Usuario de acceso', 'example' => 'admin'],
                ['field' => 'password', 'required' => false, 'description' => 'Contraseña de acceso', 'example' => 'pass789'],
                ['field' => 'ssid', 'required' => false, 'description' => 'SSID de la red', 'example' => 'ISP-Norte'],
                ['field' => 'frecuencia', 'required' => false, 'description' => 'Frecuencia en MHz', 'example' => '5180'],
                ['field' => 'node_tower', 'required' => false, 'description' => 'Nodo / Torre', 'example' => 'Torre A'],
                ['field' => 'comments', 'required' => false, 'description' => 'Comentarios adicionales', 'example' => 'Cobertura norte'],
            ],
            'Planes' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre del plan', 'example' => 'Internet 10MB'],
                ['field' => 'costo', 'required' => true, 'description' => 'Precio mensual', 'example' => '25000'],
                ['field' => 'tipo_plan', 'required' => true, 'description' => 'Tipo de plan. Valores válidos: pppoe, queue, hotspot, pcq', 'example' => 'pppoe'],
                ['field' => 'speed_down', 'required' => true, 'description' => 'Velocidad de descarga (10M, 100M)', 'example' => '10M'],
                ['field' => 'speed_up', 'required' => true, 'description' => 'Velocidad de subida (5M, 50M)', 'example' => '5M'],
                ['field' => 'descripcion', 'required' => false, 'description' => 'Descripción adicional (referencia)', 'example' => 'Plan básico'],
            ],
            'Clientes' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre del cliente', 'example' => 'Juan'],
                ['field' => 'apellido', 'required' => true, 'description' => 'Apellido del cliente', 'example' => 'Pérez'],
                ['field' => 'ip_usuario', 'required' => true, 'description' => 'IP asignada al cliente', 'example' => '10.0.0.5'],
                ['field' => 'ip_router', 'required' => true, 'description' => 'IP del router (debe existir o estar en la hoja Routers)', 'example' => '192.168.1.1'],
                ['field' => 'nombre_plan', 'required' => true, 'description' => 'Nombre del plan (debe existir o estar en la hoja Planes)', 'example' => 'Internet 10MB'],
                ['field' => 'nombre_sectorial', 'required' => true, 'description' => 'Nombre de la sectorial asignada (debe existir)', 'example' => 'Sectorial Norte'],
                ['field' => 'email', 'required' => false, 'description' => 'Email único. Si se omite, se genera como nombre.apellido@dominio-tenant', 'example' => 'juan@mail.com'],
                ['field' => 'cedula', 'required' => false, 'description' => 'Cédula / documento de identidad del cliente', 'example' => '1010101010'],
                ['field' => 'telefono', 'required' => false, 'description' => 'Teléfono de contacto', 'example' => '3001234567'],
                ['field' => 'direccion', 'required' => false, 'description' => 'Dirección física', 'example' => 'Calle 1 #2-3'],
                ['field' => 'ciudad', 'required' => false, 'description' => 'Ciudad de residencia', 'example' => 'Bogotá'],
                ['field' => 'usuario_pppoe', 'required' => false, 'description' => 'Usuario PPPoE (obligatorio si el router tiene Control PPPOE activo)', 'example' => 'juan.perez'],
                ['field' => 'password_pppoe', 'required' => false, 'description' => 'Contraseña PPPoE (obligatoria si el router tiene Control PPPOE activo)', 'example' => 'secret123'],
                ['field' => 'fecha_instalacion', 'required' => false, 'description' => 'Fecha de instalación del servicio (formato AAAA-MM-DD)', 'example' => '2026-05-28'],
                ['field' => 'estrato', 'required' => false, 'description' => 'Estrato socioeconómico (número del 1 al 6)', 'example' => '3'],
            ],
        ]);
    }

    private function buildSummaryMessage(array $summary, array $errors): string
    {
        $parts = [];
        if ($summary['routers']) $parts[] = "{$summary['routers']} router(s)";
        if ($summary['sectoriales']) $parts[] = "{$summary['sectoriales']} sectorial(es)";
        if ($summary['planes']) $parts[] = "{$summary['planes']} plan(es)";
        if ($summary['clientes']) $parts[] = "{$summary['clientes']} cliente(s)";

        if (empty($parts) && empty($errors)) {
            return 'No se encontraron filas para importar.';
        }

        $imported = empty($parts) ? 'Ninguna fila importada' : 'Importado: ' . implode(', ', $parts);

        if (!empty($errors)) {
            return $imported . '. Se encontraron ' . count($errors) . ' errores.';
        }

        return $imported;
    }
}
