<?php
namespace App\Exports;

use App\Exports\Sheets\CustomersSheet;
use App\Exports\Sheets\RoutersSheet;
use App\Exports\Sheets\SectorialsSheet;
use App\Exports\Sheets\ServicePlansSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class UnifiedTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new RoutersSheet(),
            new SectorialsSheet(),
            new ServicePlansSheet(),
            new CustomersSheet(),
        ];
    }
}
