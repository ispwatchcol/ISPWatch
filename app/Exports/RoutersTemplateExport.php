<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RoutersTemplateExport implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return ['nombre', 'ip', 'puerto', 'usuario', 'password', 'tipo_corte', 'wan_interface'];
    }

    public function collection()
    {
        return collect([
            ['Torre Centro', '192.168.1.1', '8728', 'admin', 'pass123', 'Corte Automático', 'ether1'],
            ['Torre Norte', '192.168.1.2', '8728', 'admin', 'pass456', 'Corte Manual', 'ether1'],
        ]);
    }
}
