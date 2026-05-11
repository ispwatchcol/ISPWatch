<?php
namespace App\Imports\Sheets;

use App\Models\Sectorial;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Row;

class SectorialsSheetImport implements OnEachRow, WithHeadingRow, WithTitle
{
    public int $imported = 0;
    public array $errors = [];

    public function title(): string
    {
        return 'Sectoriales';
    }

    public function onRow(Row $row)
    {
        $rowNumber = $row->getIndex();
        $data = $row->toArray();

        if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
            return;
        }

        if (empty($data['nombre'])) {
            $this->errors[] = [
                'sheet' => 'Sectoriales',
                'row' => $rowNumber,
                'field' => 'nombre',
                'error' => 'El nombre es obligatorio',
            ];
            return;
        }

        try {
            Sectorial::create([
                'name' => $data['nombre'],
                'type' => $data['tipo'] ?? null,
                'ip' => $data['ip'] ?? null,
                'user_rb' => $data['usuario'] ?? null,
                'pass_rb' => $data['password'] ?? null,
                'ssid' => $data['ssid'] ?? null,
                'frequency' => $data['frecuencia'] ?? null,
                'node_tower' => $data['node_tower'] ?? null,
                'comments' => $data['comments'] ?? null,
            ]);
            $this->imported++;
        } catch (\Throwable $e) {
            $this->errors[] = [
                'sheet' => 'Sectoriales',
                'row' => $rowNumber,
                'field' => '-',
                'error' => $e->getMessage(),
            ];
        }
    }
}
