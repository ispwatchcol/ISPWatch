<?php

namespace Tests\Feature\Inventory;

use App\Imports\InventoryImport;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryProvider;
use App\Models\InventoryStock;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk inventory import: each row is a physical device; brand/model (stock),
 * provider and branch are resolved/created by name. Mirrors the customer
 * bulk-load flow.
 */
class InventoryImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_devices_and_creates_catalog_entries_once(): void
    {
        $tenant = Tenant::factory()->create();
        $import = new InventoryImport($tenant->id);

        $import->collection(collect([
            ['marca' => 'TP-Link', 'modelo' => 'Archer C6', 'precio' => '120000', 'serial' => 'SN-1', 'mac' => 'AA:BB:CC:DD:EE:01', 'proveedor' => 'Prov A', 'sucursal' => 'Bodega'],
            ['marca' => 'TP-Link', 'modelo' => 'Archer C6', 'precio' => '120000', 'serial' => 'SN-2', 'mac' => 'AA:BB:CC:DD:EE:02', 'proveedor' => 'Prov A', 'sucursal' => 'Bodega'],
        ]));

        $this->assertSame(2, $import->imported);
        $this->assertEmpty($import->errors);

        // Two devices, but the shared brand/model, provider and branch are
        // created only once (reused across rows).
        $this->assertSame(2, InventoryDevice::withoutTenantScope()->count());
        $this->assertSame(1, InventoryStock::withoutTenantScope()->count());
        $this->assertSame(1, InventoryProvider::withoutTenantScope()->count());
        $this->assertSame(1, InventoryBranch::withoutTenantScope()->count());

        $stock = InventoryStock::withoutTenantScope()->first();
        $this->assertSame('TP-Link', $stock->brand);
        $this->assertEquals(120000, (float) $stock->price);
        $this->assertSame($tenant->id, (int) $stock->tenant_id);

        $device = InventoryDevice::withoutTenantScope()->where('serial', 'SN-1')->first();
        $this->assertSame($tenant->id, (int) $device->tenant_id);
        $this->assertSame($stock->id, (int) $device->stock_id);
    }

    #[Test]
    public function it_rejects_duplicate_serials_within_the_file(): void
    {
        $tenant = Tenant::factory()->create();
        $import = new InventoryImport($tenant->id);

        $import->collection(collect([
            ['marca' => 'Mikrotik', 'modelo' => 'hEX', 'serial' => 'DUP', 'mac' => '', 'proveedor' => '', 'sucursal' => ''],
            ['marca' => 'Mikrotik', 'modelo' => 'hEX', 'serial' => 'DUP', 'mac' => '', 'proveedor' => '', 'sucursal' => ''],
        ]));

        $this->assertSame(1, $import->imported);
        $this->assertCount(1, $import->errors);
        $this->assertSame('serial', $import->errors[0]['field']);
        $this->assertSame(1, InventoryDevice::withoutTenantScope()->count());
    }

    #[Test]
    public function it_flags_a_row_with_no_identifying_field(): void
    {
        $tenant = Tenant::factory()->create();
        $import = new InventoryImport($tenant->id);

        // Only a price — nothing to identify the device by.
        $import->collection(collect([
            ['marca' => '', 'modelo' => '', 'precio' => '5000', 'serial' => '', 'mac' => '', 'proveedor' => '', 'sucursal' => ''],
        ]));

        $this->assertSame(0, $import->imported);
        $this->assertCount(1, $import->errors);
        $this->assertSame(0, InventoryDevice::withoutTenantScope()->count());
    }
}
