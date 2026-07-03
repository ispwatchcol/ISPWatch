<?php
namespace App\Imports;

use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryProvider;
use App\Models\InventoryStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Bulk import of inventory devices (physical units) — the inventory counterpart
 * of CustomersSheetImport. Each row is one device (serial/MAC) tied to a
 * brand/model (stock), a provider and a branch. Catalog entries (stock /
 * provider / branch) are resolved by name and CREATED on the fly when they
 * don't exist yet, so a single Excel can bootstrap the whole inventory.
 *
 * Two-phase like the customer import: validate + resolve everything first
 * (granular per-row errors), then bulk-insert the device rows. Catalog lookups
 * are cached in memory so no per-row DB query happens in the hot path.
 */
class InventoryImport implements ToCollection, WithHeadingRow, WithTitle
{
    private const CHUNK = 300;

    protected $tenantId;
    public int $imported = 0;
    public array $errors = [];

    /** "brand|model" (lowercased) => stock_id */
    protected array $stockCache = [];
    /** lowercased provider name => provider_id */
    protected array $providerCache = [];
    /** lowercased branch name => branch_id */
    protected array $branchCache = [];
    /** lowercased serial => true (dedupe within tenant + within file) */
    protected array $existingSerials = [];
    /** lowercased mac => true */
    protected array $existingMacs = [];

    public function __construct($tenantId)
    {
        $this->tenantId = $tenantId;
        $this->loadCaches();
    }

    public function title(): string
    {
        return 'Inventario';
    }

    /**
     * Preload the tenant's catalog + used serials/macs so the row loop never
     * hits the DB per row. Models use BelongsToTenant, but we filter explicitly
     * to stay correct even outside an auth scope.
     */
    protected function loadCaches(): void
    {
        foreach (InventoryStock::withoutTenantScope()->where('tenant_id', $this->tenantId)->get() as $stock) {
            $this->stockCache[$this->stockKey($stock->brand, $stock->model)] = $stock->id;
        }

        foreach (InventoryProvider::withoutTenantScope()->where('tenant_id', $this->tenantId)->get() as $provider) {
            $this->providerCache[mb_strtolower(trim((string) $provider->name))] = $provider->id;
        }

        foreach (InventoryBranch::withoutTenantScope()->where('tenant_id', $this->tenantId)->get() as $branch) {
            $this->branchCache[mb_strtolower(trim((string) $branch->name))] = $branch->id;
        }

        $devices = InventoryDevice::withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->get(['serial', 'mac']);

        foreach ($devices as $device) {
            if (!empty($device->serial)) {
                $this->existingSerials[mb_strtolower(trim($device->serial))] = true;
            }
            if (!empty($device->mac)) {
                $this->existingMacs[mb_strtolower(trim($device->mac))] = true;
            }
        }
    }

    public function collection(Collection $rows)
    {
        $pending = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +1 heading, +1 to be 1-based
            $data = is_array($row) ? $row : $row->toArray();

            // Skip fully-empty rows.
            if (empty(array_filter($data, fn($v) => $v !== null && $v !== ''))) {
                continue;
            }

            $brand    = $this->str($data['marca'] ?? null);
            $model    = $this->str($data['modelo'] ?? null);
            $serial   = $this->str($data['serial'] ?? null);
            $mac      = $this->str($data['mac'] ?? null);
            $provider = $this->str($data['proveedor'] ?? null);
            $branch   = $this->str($data['sucursal'] ?? null);

            // A device must be identifiable by SOMETHING: brand/model or serial/mac.
            if ($brand === null && $model === null && $serial === null && $mac === null) {
                $this->errors[] = $this->err($rowNumber, 'marca, modelo, serial, mac',
                    'La fila no tiene marca, modelo, serial ni MAC. Indica al menos uno.');
                continue;
            }

            // Serial uniqueness (tenant + in-file).
            if ($serial !== null) {
                $serialKey = mb_strtolower($serial);
                if (isset($this->existingSerials[$serialKey])) {
                    $this->errors[] = $this->err($rowNumber, 'serial',
                        "El serial {$serial} ya está registrado en el inventario.");
                    continue;
                }
            }

            // MAC uniqueness (tenant + in-file).
            if ($mac !== null) {
                $macKey = mb_strtolower($mac);
                if (isset($this->existingMacs[$macKey])) {
                    $this->errors[] = $this->err($rowNumber, 'mac',
                        "La MAC {$mac} ya está registrada en el inventario.");
                    continue;
                }
            }

            $price = $this->parsePrice($data['precio'] ?? null);
            if (($data['precio'] ?? '') !== '' && $data['precio'] !== null && $price === null) {
                $this->errors[] = $this->err($rowNumber, 'precio',
                    'Precio inválido. Usa solo números (ej. 120000).');
                continue;
            }

            try {
                $stockId    = ($brand !== null || $model !== null) ? $this->resolveStock($brand, $model, $price) : null;
                $providerId = $provider !== null ? $this->resolveProvider($provider) : null;
                $branchId   = $branch !== null ? $this->resolveBranch($branch) : null;
            } catch (\Throwable $e) {
                $this->errors[] = $this->err($rowNumber, 'catalogo',
                    'No se pudo crear/resolver marca-modelo, proveedor o sucursal: ' . $e->getMessage());
                continue;
            }

            // Reserve serial/mac so a later duplicate in the SAME file is caught.
            if ($serial !== null) {
                $this->existingSerials[mb_strtolower($serial)] = true;
            }
            if ($mac !== null) {
                $this->existingMacs[mb_strtolower($mac)] = true;
            }

            $pending[] = [
                'tenant_id'   => $this->tenantId,
                'stock_id'    => $stockId,
                'provider_id' => $providerId,
                'branch_id'   => $branchId,
                'user_id'     => null,
                'serial'      => $serial,
                'mac'         => $mac,
            ];
        }

        if (empty($pending)) {
            return;
        }

        $this->flush($pending);
    }

    /** Bulk-insert device rows in chunks (atomic per run). */
    protected function flush(array $pending): void
    {
        $now = now();
        $rows = array_map(fn($p) => $p + ['created_at' => $now, 'updated_at' => $now], $pending);

        try {
            DB::transaction(function () use ($rows) {
                foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                    InventoryDevice::insert($chunk);
                }
            });
            $this->imported += count($pending);
        } catch (\Throwable $e) {
            $this->errors[] = $this->err('-', '-',
                'No se pudo guardar el lote de equipos: ' . $e->getMessage());
        }
    }

    /** Find-or-create a stock (brand+model) entry; caches by brand|model. */
    protected function resolveStock(?string $brand, ?string $model, ?float $price): int
    {
        $key = $this->stockKey($brand, $model);
        if (isset($this->stockCache[$key])) {
            return $this->stockCache[$key];
        }

        $stock = new InventoryStock([
            'brand' => $brand,
            'model' => $model,
            'price' => $price,
        ]);
        $stock->tenant_id = $this->tenantId; // insert() path skips the auto-tenant hook; set it explicitly
        $stock->save();

        return $this->stockCache[$key] = $stock->id;
    }

    /** Find-or-create a provider by name; caches by lowercased name. */
    protected function resolveProvider(string $name): int
    {
        $key = mb_strtolower($name);
        if (isset($this->providerCache[$key])) {
            return $this->providerCache[$key];
        }

        $provider = new InventoryProvider(['name' => $name]);
        $provider->tenant_id = $this->tenantId;
        $provider->save();

        return $this->providerCache[$key] = $provider->id;
    }

    /** Find-or-create a branch by name; caches by lowercased name. */
    protected function resolveBranch(string $name): int
    {
        $key = mb_strtolower($name);
        if (isset($this->branchCache[$key])) {
            return $this->branchCache[$key];
        }

        $branch = new InventoryBranch(['name' => $name]);
        $branch->tenant_id = $this->tenantId;
        $branch->save();

        return $this->branchCache[$key] = $branch->id;
    }

    protected function stockKey(?string $brand, ?string $model): string
    {
        return mb_strtolower(trim((string) $brand)) . '|' . mb_strtolower(trim((string) $model));
    }

    /** Trim a cell to a non-empty string, or null. */
    protected function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    /**
     * Parse a price cell into a float. Accepts plain numbers and common money
     * formatting ("$120.000", "120,000", "120000.50"). Returns null on garbage.
     */
    protected function parsePrice($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Strip currency symbols/spaces; keep digits, separators and sign.
        $clean = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($clean === '' || $clean === null) {
            return null;
        }

        // If both separators present, assume the LAST one is the decimal sep.
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            if (strrpos($clean, ',') > strrpos($clean, '.')) {
                $clean = str_replace('.', '', $clean);      // 1.234,56 -> 1234,56
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);      // 1,234.56 -> 1234.56
            }
        } else {
            // Single separator: treat comma as decimal, drop stray thousands dots.
            if (str_contains($clean, ',')) {
                $clean = str_replace(',', '.', $clean);
            }
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected function err($row, string $field, string $error): array
    {
        return [
            'sheet' => 'Inventario',
            'row'   => $row,
            'field' => $field,
            'error' => $error,
        ];
    }
}
