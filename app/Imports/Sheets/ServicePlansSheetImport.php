<?php
namespace App\Imports\Sheets;

use App\Models\Plan;
use App\Models\TypePlan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;

class ServicePlansSheetImport implements ToCollection, WithHeadingRow, WithTitle
{
    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];
    protected $typePlans = [];

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->typePlans = TypePlan::pluck('id', 'code')->toArray();
    }

    public function title(): string
    {
        return 'Planes';
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $missing = [];
            foreach (['nombre', 'costo', 'tipo_plan', 'speed_down', 'speed_up'] as $field) {
                if (empty($data[$field])) {
                    $missing[] = $field;
                }
            }
            if (!empty($missing)) {
                $this->errors[] = [
                    'sheet' => 'Planes',
                    'row' => $rowNumber,
                    'field' => implode(', ', $missing),
                    'error' => 'Campos obligatorios faltantes',
                ];
                continue;
            }

            $isCourtesy = is_string($data['costo'])
                && strtoupper(trim($data['costo'])) === 'CORTESIA';

            if (!$isCourtesy && !is_numeric($data['costo'])) {
                $this->errors[] = [
                    'sheet' => 'Planes',
                    'row' => $rowNumber,
                    'field' => 'costo',
                    'error' => "El costo debe ser numérico o 'CORTESIA'",
                ];
                continue;
            }

            if (!isset($this->typePlans[$data['tipo_plan']])) {
                $this->errors[] = [
                    'sheet' => 'Planes',
                    'row' => $rowNumber,
                    'field' => 'tipo_plan',
                    'error' => "Tipo de plan '{$data['tipo_plan']}' no encontrado (válidos: queue, pppoe, hotspot, pcq)",
                ];
                continue;
            }

            if (Plan::where('name', $data['nombre'])->where('tenant_id', $this->tenantId)->exists()) {
                $this->errors[] = [
                    'sheet' => 'Planes',
                    'row' => $rowNumber,
                    'field' => 'nombre',
                    'error' => "Ya existe un plan con el nombre '{$data['nombre']}'",
                ];
                continue;
            }

            try {
                Plan::create([
                    'name' => $data['nombre'],
                    'cost_product' => $isCourtesy ? 0 : $data['costo'],
                    'is_courtesy' => $isCourtesy,
                    'speed_down' => $data['speed_down'],
                    'speed_up' => $data['speed_up'],
                    'type_plan_id' => $this->typePlans[$data['tipo_plan']],
                    'tenant_id' => $this->tenantId,
                ]);
                $this->imported++;
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'sheet' => 'Planes',
                    'row' => $rowNumber,
                    'field' => '-',
                    'error' => $e->getMessage(),
                ];
            }
        }
    }
}
