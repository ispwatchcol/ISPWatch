<?php
namespace App\Exports;

use App\Exports\Sheets\ImportErrorsSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportErrorsExport implements WithMultipleSheets
{
    public function __construct(protected array $errors) {}

    public function sheets(): array
    {
        $grouped = [];
        foreach ($this->errors as $err) {
            $sheet = $err['sheet'] ?? 'Otros';
            $grouped[$sheet][] = $err;
        }

        $sheets = [];
        foreach (['Routers', 'Sectoriales', 'Planes', 'Clientes', 'Otros'] as $name) {
            if (!empty($grouped[$name])) {
                $sheets[] = new ImportErrorsSheet($name, $grouped[$name]);
            }
        }

        return $sheets;
    }
}
