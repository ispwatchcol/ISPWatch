<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\RoutersTemplateExport;
use App\Exports\ServicePlansTemplateExport;
use App\Exports\CustomersTemplateExport;
use App\Imports\RoutersImport;
use App\Imports\ServicePlansImport;
use App\Imports\CustomersImport;

class ImportController extends Controller
{
    public function downloadTemplate($type)
    {
        $templates = [
            'routers' => new RoutersTemplateExport(),
            'service-plans' => new ServicePlansTemplateExport(),
            'customers' => new CustomersTemplateExport(),
        ];

        if (!isset($templates[$type])) {
            return response()->json(['error' => 'Invalid type'], 400);
        }

        return Excel::download($templates[$type], "plantilla_{$type}.xlsx");
    }

    public function import(Request $request, $type)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:10240',
        ]);

        $imports = [
            'routers' => RoutersImport::class,
            'service-plans' => ServicePlansImport::class,
            'customers' => CustomersImport::class,
        ];

        if (!isset($imports[$type])) {
            return response()->json(['error' => 'Invalid type'], 400);
        }

        try {
            $import = new $imports[$type]();
            Excel::import($import, $request->file('file'));

            // Note: ToModel doesn't provide getRowCount() easily unless using WithChunkReading or similar, 
            // but we can assume success if no exception.
            // Using WithValidation allows getFailures() via validation exception, but manual tracking might be needed for counts 
            // if not using Importable trait.

            return response()->json([
                'success' => true,
                'summary' => [
                    'imported' => 'Proceso completado', // Simplified
                ],
                'errors' => [],
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();

            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'field' => $failure->attribute(),
                    'error' => implode(', ', $failure->errors()),
                ];
            }

            return response()->json([
                'success' => false,
                'errors' => $errors,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function fieldDocs($type)
    {
        $docs = [
            'routers' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre descriptivo del router', 'example' => 'Torre Centro'],
                ['field' => 'ip', 'required' => true, 'description' => 'Dirección IP única del router', 'example' => '192.168.1.1'],
                ['field' => 'puerto', 'required' => false, 'description' => 'Puerto API MikroTik (default: 8728)', 'example' => '8728'],
                ['field' => 'usuario', 'required' => true, 'description' => 'Usuario de acceso RouterOS', 'example' => 'admin'],
                ['field' => 'password', 'required' => true, 'description' => 'Contraseña de acceso', 'example' => 'password123'],
                ['field' => 'tipo_corte', 'required' => true, 'description' => 'Tipo de corte: "Corte Automático", "Corte Manual", "Sin Corte"', 'example' => 'Corte Automático'],
                ['field' => 'wan_interface', 'required' => false, 'description' => 'Interfaz WAN (default: ether1)', 'example' => 'ether1'],
            ],
            'service-plans' => [
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre del plan', 'example' => 'Internet 10MB'],
                ['field' => 'costo', 'required' => true, 'description' => 'Precio mensual en COP', 'example' => '25000'],
                ['field' => 'tipo_plan', 'required' => true, 'description' => 'Tipo: Nombre exacto del tipo (ej: PPPoE, Hotspot)', 'example' => 'PPPoE'],
                ['field' => 'descripcion', 'required' => false, 'description' => 'Descripción adicional', 'example' => 'Plan básico residencial'],
            ],
            'customers' => [
                ['field' => 'email', 'required' => true, 'description' => 'Email único del cliente', 'example' => 'juan@mail.com'],
                ['field' => 'nombre', 'required' => true, 'description' => 'Nombre del cliente', 'example' => 'Juan'],
                ['field' => 'apellido', 'required' => true, 'description' => 'Apellido del cliente', 'example' => 'Pérez'],
                ['field' => 'telefono', 'required' => false, 'description' => 'Teléfono de contacto', 'example' => '3001234567'],
                ['field' => 'direccion', 'required' => false, 'description' => 'Dirección física', 'example' => 'Calle 1 #2-3'],
                ['field' => 'ciudad', 'required' => false, 'description' => 'Ciudad de residencia', 'example' => 'Bogotá'],
                ['field' => 'ip_usuario', 'required' => false, 'description' => 'IP asignada al cliente', 'example' => '10.0.0.5'],
                ['field' => 'ip_router', 'required' => true, 'description' => 'IP del router (debe existir previamente)', 'example' => '192.168.1.1'],
                ['field' => 'nombre_plan', 'required' => true, 'description' => 'Nombre del plan (debe existir previamente)', 'example' => 'Internet 10MB'],
            ],
        ];

        return response()->json($docs[$type] ?? []);
    }
}
