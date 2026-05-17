<?php
namespace App\Imports\Sheets;

use App\Models\CutType;
use App\Models\Router;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;

class RoutersSheetImport implements ToCollection, WithHeadingRow, WithTitle
{
    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];
    protected $cutTypes = [];

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->cutTypes = CutType::pluck('id', 'name')->toArray();
    }

    public function title(): string
    {
        return 'Routers';
    }

    private function parseBool($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int)$value === 1;
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['sí', 'si', 'yes', 'true', '1', 'x'], true);
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 because index is 0-based and row 1 is headings
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
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
                continue;
            }

            if (!filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                $this->errors[] = [
                    'sheet' => 'Routers',
                    'row' => $rowNumber,
                    'field' => 'ip',
                    'error' => 'Dirección IP inválida',
                ];
                continue;
            }

            if (Router::where('ip', $data['ip'])->where('tenant_id', $this->tenantId)->exists()) {
                $this->errors[] = [
                    'sheet' => 'Routers',
                    'row' => $rowNumber,
                    'field' => 'ip',
                    'error' => "Ya existe un router con la IP {$data['ip']}",
                ];
                continue;
            }

            if (Router::where('name', $data['nombre'])->where('tenant_id', $this->tenantId)->exists()) {
                $this->errors[] = [
                    'sheet' => 'Routers',
                    'row' => $rowNumber,
                    'field' => 'nombre',
                    'error' => "Ya existe un router con el nombre '{$data['nombre']}'",
                ];
                continue;
            }

            if (!isset($this->cutTypes[$data['tipo_corte']])) {
                $this->errors[] = [
                    'sheet' => 'Routers',
                    'row' => $rowNumber,
                    'field' => 'tipo_corte',
                    'error' => "Tipo de corte '{$data['tipo_corte']}' no encontrado (válidos: Corte Automático, Corte Manual, Sin Corte)",
                ];
                continue;
            }

            try {
                Router::create([
                    'name' => $data['nombre'],
                    'ip' => $data['ip'],
                    'puerto_api' => $data['puerto'] ?? 8728,
                    'user_rb' => $data['usuario'],
                    'password_rb' => $data['password'],
                    'cut_type_id' => $this->cutTypes[$data['tipo_corte']],
                    'wan_interface' => $data['wan_interface'] ?? 'ether1',
                    'pppoe' => $this->parseBool($data['pppoe'] ?? null),
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
}
