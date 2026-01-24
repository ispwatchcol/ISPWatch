<?php
namespace App\Imports;

use App\Models\Router;
use App\Models\CutType;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class RoutersImport implements ToModel, WithHeadingRow, WithValidation
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
        // Lookup cut_type by Spanish name
        $cutType = CutType::where('name', $row['tipo_corte'])->first();

        return new Router([
            'name' => $row['nombre'],
            'ip' => $row['ip'],
            'port' => $row['puerto'] ?? 8728,
            'user_rb' => $row['usuario'],
            'password_rb' => $row['password'],
            'cut_type_id' => $cutType?->id,
            'wan_interface' => $row['wan_interface'] ?? 'ether1',
            'tenant_id' => $this->tenantId,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string',
            'ip' => 'required|ip|unique:router,ip',
            'tipo_corte' => 'required|exists:cut_type,name',
        ];
    }
}
