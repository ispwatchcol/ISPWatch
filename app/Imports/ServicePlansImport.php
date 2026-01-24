<?php
namespace App\Imports;

use App\Models\Plan;
use App\Models\TypePlan;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ServicePlansImport implements ToModel, WithHeadingRow, WithValidation
{
    private $rows = 0;
    protected $tenantId;

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function model(array $row)
    {
        $this->rows++;
        // Lookup type_plan by name
        $typePlan = TypePlan::where('code', $row['tipo_plan'])->first();

        return new Plan([
            'name' => $row['nombre'],
            'cost_product' => $row['costo'],
            'speed_down' => $row['speed_down'],
            'speed_up' => $row['speed_up'],
            'type_plan_id' => $typePlan?->id,
            // 'description'  => $row['descripcion'] ?? null, // Plan model does not have description
            'tenant_id' => $this->tenantId,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string',
            'costo' => 'required|numeric',
            'tipo_plan' => 'required|exists:type_plans,code',
            'speed_down' => 'required|string',
            'speed_up' => 'required|string',
        ];
    }
}
