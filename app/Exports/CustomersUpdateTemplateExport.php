<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomersUpdateTemplateExport implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Clientes';
    }

    public function headings(): array
    {
        return [
            'email_actual',
            'nuevo_email',
            'nombre',
            'apellido',
            'cedula',
            'telefono',
            'direccion',
            'ciudad',
            'ip_usuario',
            'ip_router',
            'nombre_plan',
            'nombre_sectorial',
            'usuario_pppoe',
            'password_pppoe',
            'password',
        ];
    }

    public function collection()
    {
        return collect([
            ['juan@mail.com', '', 'Juan', 'Pérez', '1010101010', '3001234567', 'Calle 1 #2-3', 'Bogotá', '10.0.0.5', '192.168.1.1', 'Internet 10MB', 'Sectorial Norte', '', '', ''],
            ['maria@mail.com', 'maria.nueva@mail.com', '', '', '', '', '', '', '10.0.0.11', '192.168.1.2', '', 'Sectorial Sur', '', '', ''],
        ]);
    }
}
