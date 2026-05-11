<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class SectorialsSheet implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Sectoriales';
    }

    public function headings(): array
    {
        return ['nombre', 'tipo', 'ip', 'usuario', 'password', 'ssid', 'frecuencia', 'node_tower', 'comments'];
    }

    public function collection()
    {
        return collect([
            ['Sectorial Norte', 'omni', '192.168.10.1', 'admin', 'pass789', 'ISP-Norte', '5180', 'Torre A', 'Cobertura norte'],
            ['Sectorial Sur', 'panel', '192.168.10.2', 'admin', 'pass012', 'ISP-Sur', '5200', 'Torre B', 'Cobertura sur'],
        ]);
    }
}
