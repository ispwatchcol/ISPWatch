<?php
namespace App\Imports\Sheets;

use App\Models\CutType;
use App\Models\Router;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Row;

class RoutersSheetImport implements OnEachRow, WithHeadingRow, WithTitle
{
    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function title(): string
    {
        return 'Routers';
    }

    public function onRow(Row $row)
    {
        $rowNumber = $row->getIndex();
        $data = $row->toArray();

        // Skip fully empty rows
        if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
            return;
        }

        $missing = [];
        foreach (['nombre', 'ip', 'usuario', 'password', 'tipo_corte'] as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            $this->errors[] = [
                'sheet' => 'Routers',
                'row' => $rowNumber,
                'field' => implode(', ', $missing),
                'error' => 'Campos obligatorios faltantes',
            ];
            return;
        }

        if (!filter_var($data['ip'], FILTER_VALIDATE_IP)) {
            $this->errors[] = [
                'sheet' => 'Routers',
                'row' => $rowNumber,
                'field' => 'ip',
                'error' => 'Dirección IP inválida',
            ];
            return;
        }

        if (Router::where('ip', $data['ip'])->where('tenant_id', $this->tenantId)->exists()) {
            $this->errors[] = [
                'sheet' => 'Routers',
                'row' => $rowNumber,
                'field' => 'ip',
                'error' => "Ya existe un router con la IP {$data['ip']}",
            ];
            return;
        }

        $cutType = CutType::where('name', $data['tipo_corte'])->first();
        if (!$cutType) {
            $this->errors[] = [
                'sheet' => 'Routers',
                'row' => $rowNumber,
                'field' => 'tipo_corte',
                'error' => "Tipo de corte '{$data['tipo_corte']}' no encontrado (válidos: Corte Automático, Corte Manual, Sin Corte)",
            ];
            return;
        }

        try {
            Router::create([
                'name' => $data['nombre'],
                'ip' => $data['ip'],
                'puerto_api' => $data['puerto'] ?? 8728,
                'user_rb' => $data['usuario'],
                'password_rb' => $data['password'],
                'cut_type_id' => $cutType->id,
                'wan_interface' => $data['wan_interface'] ?? 'ether1',
                'tenant_id' => $this->tenantId,
                'firmware_version' => 'unknown',
                'status' => 'active',
            ]);
            $this->imported++;
        } catch (\Throwable $e) {
            $this->errors[] = [
                'sheet' => 'Routers',
                'row' => $rowNumber,
                'field' => '-',
                'error' => $e->getMessage(),
            ];
        }
    }
}
