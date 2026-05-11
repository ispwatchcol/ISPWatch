<?php
namespace App\Imports\Sheets;

use App\Models\Sectorial;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;

class SectorialsSheetImport implements ToCollection, WithHeadingRow, WithTitle
{
    public int $imported = 0;
    public array $errors = [];

    public function title(): string
    {
        return 'Sectoriales';
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data = is_array($row) ? $row : $row->toArray();

            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if (empty($data['nombre'])) {
                $this->errors[] = [
                    'sheet' => 'Sectoriales',
                    'row' => $rowNumber,
                    'field' => 'nombre',
                    'error' => 'El nombre es obligatorio',
                ];
                continue;
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
}
