<?php
namespace App\Http\Controllers;

use App\Exports\UnifiedTemplateExport;
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

    public function fieldDocs()
    {
        return response()->json([
            'Routers' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre descriptivo del router', 'example' => 'Torre Centro'],
                ['field' => 'ip', 'required' => true, 'description' => 'Dirección IP única del router', 'example' => '192.168.1.1'],
                ['field' => 'usuario', 'required' => true, 'description' => 'Usuario de acceso RouterOS', 'example' => 'admin'],
                ['field' => 'password', 'required' => true, 'description' => 'Contraseña de acceso', 'example' => 'password123'],
                ['field' => 'tipo_corte', 'required' => true, 'description' => 'Corte Automático, Corte Manual o Sin Corte', 'example' => 'Corte Automático'],
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
                ['field' => 'tipo_plan', 'required' => true, 'description' => 'Código: queue, pppoe, hotspot o pcq', 'example' => 'pppoe'],
                ['field' => 'speed_down', 'required' => true, 'description' => 'Velocidad de descarga (10M, 100M)', 'example' => '10M'],
                ['field' => 'speed_up', 'required' => true, 'description' => 'Velocidad de subida (5M, 50M)', 'example' => '5M'],
                ['field' => 'descripcion', 'required' => false, 'description' => 'Descripción adicional (referencia)', 'example' => 'Plan básico'],
            ],
            'Clientes' => [
                ['field' => 'email', 'required' => true, 'description' => 'Email único del cliente', 'example' => 'juan@mail.com'],
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre del cliente', 'example' => 'Juan'],
                ['field' => 'apellido', 'required' => true, 'description' => 'Apellido del cliente', 'example' => 'Pérez'],
                ['field' => 'ip_router', 'required' => true, 'description' => 'IP del router (debe existir o estar en la hoja Routers)', 'example' => '192.168.1.1'],
                ['field' => 'nombre_plan', 'required' => true, 'description' => 'Nombre del plan (debe existir o estar en la hoja Planes)', 'example' => 'Internet 10MB'],
                ['field' => 'telefono', 'required' => false, 'description' => 'Teléfono de contacto', 'example' => '3001234567'],
                ['field' => 'direccion', 'required' => false, 'description' => 'Dirección física', 'example' => 'Calle 1 #2-3'],
                ['field' => 'ciudad', 'required' => false, 'description' => 'Ciudad de residencia', 'example' => 'Bogotá'],
                ['field' => 'ip_usuario', 'required' => false, 'description' => 'IP asignada al cliente', 'example' => '10.0.0.5'],
                ['field' => 'nombre_sectorial', 'required' => false, 'description' => 'Nombre de la sectorial asignada', 'example' => 'Sectorial Norte'],
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
