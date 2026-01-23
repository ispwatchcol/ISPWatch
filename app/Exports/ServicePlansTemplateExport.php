<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ServicePlansTemplateExport implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return ['nombre', 'costo', 'speed_down', 'speed_up', 'tipo_plan', 'descripcion'];
    }

    public function collection()
    {
        return collect([
            ['Internet 10MB', '25000', '10M', '5M', 'pppoe', 'Plan residencial básico'],
            ['Internet 50MB', '75000', '50M', '10M', 'hotspot', 'Plan empresarial'],
        ]);
    }
}
