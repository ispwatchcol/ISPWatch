<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ServicePlansSheet implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Planes';
    }

    public function headings(): array
    {
        return ['nombre', 'costo', 'speed_down', 'speed_up', 'tipo_plan', 'descripcion'];
    }

    public function collection()
    {
        return collect([
            ['Internet 10MB', '25000', '10M', '5M', 'pppoe', 'Plan residencial básico'],
            ['Internet 50MB', '75000', '50M', '10M', 'queue', 'Plan empresarial'],
            ['Cortesía 10MB', 'CORTESIA', '10M', '5M', 'pppoe', 'Plan de cortesía (sin costo)'],
        ]);
    }
}
