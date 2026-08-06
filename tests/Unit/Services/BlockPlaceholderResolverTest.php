<?php

namespace Tests\Unit\Services;

use App\Models\CustomerInstallation;
use App\Models\Tenant;
use App\Services\Templates\BlockPlaceholderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlockPlaceholderResolverTest extends TestCase
{
    use RefreshDatabase;

    private BlockPlaceholderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new BlockPlaceholderResolver();
    }

    public function test_for_installation_resolves_signature_images_when_present(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        $values = $this->resolver->forInstallation(
            $installation,
            $tenant,
            'data:image/png;base64,cliente',
            'data:image/png;base64,tecnico'
        );

        $this->assertStringContainsString('data:image/png;base64,cliente', $values['instalacion.firma_cliente']);
        $this->assertStringContainsString('data:image/png;base64,tecnico', $values['instalacion.firma_tecnico']);
        // instalacion.fotos se retiró el 2026-08-05: las fotos se consultan en
        // los documentos del cliente, no dentro del PDF de la hoja.
        $this->assertArrayNotHasKey('instalacion.fotos', $values);
    }

    public function test_for_installation_resolves_missing_technician_signature_to_empty_string(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        $values = $this->resolver->forInstallation($installation, $tenant, 'data:image/png;base64,cliente', null);

        $this->assertSame('', $values['instalacion.firma_tecnico']);
    }

    /**
     * Un bloque que falla al renderizar nunca debe tumbar el documento
     * completo — degrada a vacío y queda logueado, como acordamos en el punto
     * 4 de la revisión del diseño. (Antes se forzaba con `$photos` no
     * iterable; retirado ese bloque el 2026-08-05, se fuerza con la tabla de
     * ítems, que recorre `$invoice->items` con @foreach.)
     */
    public function test_a_block_that_throws_while_rendering_degrades_to_empty_and_is_logged(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message, $context) =>
                str_contains($message, 'factura.tabla_items')
                && $context['token'] === 'factura.tabla_items'
            );

        $tenant = Tenant::factory()->create();

        // Sin persistir: el bloque sólo lee tenant_id e items.
        $invoice = new \App\Models\Invoice(['tenant_id' => $tenant->id]);
        $invoice->tenant_id = $tenant->id;

        // `@foreach($invoice->items ...)` sobre algo que no es iterable dispara
        // un error de PHP que Laravel convierte en ErrorException.
        $invoice->setRelation('items', 42);

        $values = $this->resolver->forInvoice($invoice);

        $this->assertSame('', $values['factura.tabla_items']);
    }

    // ── empresa.logo (auditoría 2026-08-03) ─────────────────────────────

    public function test_for_invoice_resolves_empresa_logo_to_empty_string_when_tenant_has_no_logo(): void
    {
        $tenant = Tenant::factory()->create(['logo' => null]);
        $customer = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = \App\Models\Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'invoice_type' => \App\Models\Invoice::TYPE_MONTHLY,
            'number'       => 'FAC-LOGO-1',
            'issue_date'   => '2026-08-01',
            'due_date'     => '2026-08-15',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'currency'     => 'COP',
            'subtotal'     => 1000,
            'tax'          => 0,
            'total'        => 1000,
            'balance_due'  => 1000,
            'status'       => 'issued',
        ])->fresh(['tenant']);

        $values = $this->resolver->forInvoice($invoice);

        $this->assertSame('', $values['empresa.logo']);
    }

    public function test_for_contract_resolves_empresa_logo_to_the_local_file_path_when_present(): void
    {
        // NUNCA crear directorios a mano bajo public_path('storage/...'): si el
        // symlink de storage:link todavía no existe en el entorno (dev fresco,
        // CI), mkdir() lo reemplaza por un directorio real que storage:link ya
        // no puede corregir después (el comando se salta la creación si el
        // destino ya existe) — pasó exactamente esto en un dev Windows real y
        // rompió el logo en toda la app hasta corregirlo a mano. En vez de eso,
        // aseguramos el symlink real primero y escribimos vía Storage::disk().
        $this->ensureStorageLinkExists();

        $relativePath = 'test-logos/logo_' . uniqid() . '.png';
        Storage::disk('public')->put($relativePath, 'fake-png-bytes');

        try {
            $tenant = Tenant::factory()->create(['logo' => $relativePath]);

            $values = $this->resolver->forContract($tenant);

            $expectedPath = str_replace('\\', '/', public_path('storage/' . $relativePath));
            $this->assertStringContainsString($expectedPath, $values['empresa.logo']);
            $this->assertStringContainsString('<img', $values['empresa.logo']);
        } finally {
            Storage::disk('public')->delete($relativePath);
        }
    }

    /**
     * No-op si el symlink ya existe (siempre debería, en un entorno correctamente
     * provisto) — sólo lo crea si falta, nunca vía mkdir() manual.
     */
    private function ensureStorageLinkExists(): void
    {
        if (!is_link(public_path('storage'))) {
            Artisan::call('storage:link');
        }
    }

    public function test_for_contract_resolves_empresa_logo_to_empty_string_when_file_is_missing_on_disk(): void
    {
        // El tenant tiene un valor de logo guardado pero el archivo no existe
        // en disco (ej. symlink storage:link ausente) — degrada a vacío,
        // igual que el resto de bloques de imagen, nunca rompe el render.
        $tenant = Tenant::factory()->create(['logo' => 'no-existe/logo.png']);

        $values = $this->resolver->forContract($tenant);

        $this->assertSame('', $values['empresa.logo']);
    }
}
