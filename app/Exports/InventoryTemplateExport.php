<?php
namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Downloadable template for the bulk inventory import. One sheet "Inventario";
 * each row is a physical device. Brand/model (stock), provider and branch are
 * created automatically by name if they don't exist yet.
 */
class InventoryTemplateExport implements FromCollection, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Inventario';
    }

    public function headings(): array
    {
        return [
            'marca',
            'modelo',
            'precio',
            'serial',
            'mac',
            'proveedor',
            'sucursal',
        ];
    }

    public function collection()
    {
        return collect([
            ['TP-Link', 'Archer C6', '120000', 'SN-0001', 'AA:BB:CC:DD:EE:01', 'Proveedor Principal', 'Bodega Central'],
            ['Mikrotik', 'hEX RB750Gr3', '250000', 'SN-0002', 'AA:BB:CC:DD:EE:02', 'Proveedor Principal', 'Bodega Central'],
        ]);
    }
}
