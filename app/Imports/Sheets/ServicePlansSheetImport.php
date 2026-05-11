<?php
namespace App\Imports\Sheets;

use App\Models\Plan;
use App\Models\TypePlan;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Row;

class ServicePlansSheetImport implements OnEachRow, WithHeadingRow, WithTitle
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
        return 'Planes';
    }

    public function onRow(Row $row)
    {
        $rowNumber = $row->getIndex();
        $data = $row->toArray();

        if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
            return;
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
            return;
        }

        if (!is_numeric($data['costo'])) {
            $this->errors[] = [
                'sheet' => 'Planes',
                'row' => $rowNumber,
                'field' => 'costo',
                'error' => 'El costo debe ser numérico',
            ];
            return;
        }

        $typePlan = TypePlan::where('code', $data['tipo_plan'])->first();
        if (!$typePlan) {
            $this->errors[] = [
                'sheet' => 'Planes',
                'row' => $rowNumber,
                'field' => 'tipo_plan',
                'error' => "Tipo de plan '{$data['tipo_plan']}' no encontrado (válidos: queue, pppoe, hotspot, pcq)",
            ];
            return;
        }

        try {
            Plan::create([
                'name' => $data['nombre'],
                'cost_product' => $data['costo'],
                'speed_down' => $data['speed_down'],
                'speed_up' => $data['speed_up'],
                'type_plan_id' => $typePlan->id,
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
