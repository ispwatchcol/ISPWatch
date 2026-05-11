<?php
namespace App\Imports;

use App\Imports\Sheets\CustomersSheetImport;
use App\Imports\Sheets\RoutersSheetImport;
use App\Imports\Sheets\SectorialsSheetImport;
use App\Imports\Sheets\ServicePlansSheetImport;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class UnifiedImport implements WithMultipleSheets, SkipsUnknownSheets
{
    protected $tenantId;

    public RoutersSheetImport $routers;
    public SectorialsSheetImport $sectorials;
    public ServicePlansSheetImport $plans;
    public CustomersSheetImport $customers;

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->routers = new RoutersSheetImport($tenantId);
        $this->sectorials = new SectorialsSheetImport();
        $this->plans = new ServicePlansSheetImport($tenantId);
        $this->customers = new CustomersSheetImport($tenantId);
    }

    public function sheets(): array
    {
        return [
            'Routers' => $this->routers,
            'Sectoriales' => $this->sectorials,
            'Planes' => $this->plans,
            'Clientes' => $this->customers,
        ];
    }

    public function onUnknownSheet($sheetName)
    {
        // Silently ignore sheets not in the expected list
    }

    public function summary(): array
    {
        return [
            'routers' => $this->routers->imported,
            'sectoriales' => $this->sectorials->imported,
            'planes' => $this->plans->imported,
            'clientes' => $this->customers->imported,
        ];
    }

    public function allErrors(): array
    {
        return array_merge(
            $this->routers->errors,
            $this->sectorials->errors,
            $this->plans->errors,
            $this->customers->errors,
        );
    }
}
