<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImportErrorsSheet implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(protected string $sheetName, protected array $errors) {}

    public function title(): string
    {
        return $this->sheetName;
    }

    public function headings(): array
    {
        return ['Fila', 'Campo', 'Error'];
    }

    public function collection()
    {
        return collect($this->errors)->map(fn($e) => [
            $e['row'] ?? '-',
            $e['field'] ?? '-',
            $e['error'] ?? '-',
        ]);
    }
}
