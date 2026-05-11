<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class RoutersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Routers';
    }

    public function headings(): array
    {
        return ['nombre', 'ip', 'puerto', 'usuario', 'password', 'tipo_corte', 'wan_interface', 'pppoe'];
    }

    public function collection()
    {
        return collect([
            ['Torre Centro', '192.168.1.1', '8728', 'admin', 'pass123', 'Corte Automático', 'ether1', 'Sí'],
            ['Torre Norte', '192.168.1.2', '8728', 'admin', 'pass456', 'Corte Manual', 'ether1', 'No'],
        ]);
    }
}
