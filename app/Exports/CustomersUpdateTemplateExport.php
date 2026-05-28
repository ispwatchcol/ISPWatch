<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class CustomersUpdateTemplateExport implements FromCollection, WithHeadings, WithTitle, WithEvents
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
            'fecha_instalacion',
            'estrato',
        ];
    }

    public function collection()
    {
        return collect([
            // Identificado por email_actual (llave principal).
            ['juan@mail.com', '', 'Juan', 'Pérez', '1010101010', '3001234567', 'Calle 1 #2-3', 'Bogotá', '10.0.0.5', '192.168.1.1', 'Internet 10MB', 'Sectorial Norte', '', '', '', '2026-05-28', '3'],
            ['maria@mail.com', 'maria.nueva@mail.com', '', '', '', '', '', '', '10.0.0.11', '192.168.1.2', '', 'Sectorial Sur', '', '', '', '', ''],
            // Sin email_actual: el cliente se identifica por la CÉDULA (debe ser única).
            ['', '', 'Carlos', 'Gómez', '2020202020', '3009998877', '', '', '', '', 'Internet 20MB', '', '', '', '', '', ''],
        ]);
    }

    public function registerEvents(): array
    {
        return [
            // Deja constancia visible en el Excel de cómo se identifica al cliente:
            // email_actual es la llave principal y la cédula el respaldo.
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getComment('A1')->getText()->createTextRun(
                    'Llave principal para identificar al cliente. Si la dejas vacía o el email no existe, se usa la columna "cedula" para identificarlo.'
                );

                $sheet->getComment('E1')->getText()->createTextRun(
                    'Identificador alterno cuando no hay email_actual. Debe ser única: si dos clientes comparten la misma cédula, esa fila se rechaza y debes usar email_actual.'
                );
            },
        ];
    }
}
