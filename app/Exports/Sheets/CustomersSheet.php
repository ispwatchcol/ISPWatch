<?php
namespace App\Exports\Sheets;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class CustomersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Clientes';
    }

    public function headings(): array
    {
        return [
            'email',
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
        ];
    }

    public function collection()
    {
        return collect([
            ['juan@mail.com', 'Juan', 'Pérez', '1010101010', '3001234567', 'Calle 1 #2-3', 'Bogotá', '10.0.0.5', '192.168.1.1', 'Internet 10MB', 'Sectorial Norte', 'juan.perez', 'secret123'],
            ['maria@mail.com', 'María', 'Gómez', '2020202020', '3009876543', 'Carrera 5 #8-9', 'Medellín', '10.0.0.10', '192.168.1.2', 'Internet 50MB', 'Sectorial Sur', 'maria.gomez', 'secret456'],
        ]);
    }
}
