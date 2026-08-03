<?php

namespace Tests\Unit\Services;

use App\Models\CustomerInstallation;
use App\Models\Tenant;
use App\Services\Templates\BlockPlaceholderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
            collect(),
            'data:image/png;base64,cliente',
            'data:image/png;base64,tecnico'
        );

        $this->assertStringContainsString('data:image/png;base64,cliente', $values['instalacion.firma_cliente']);
        $this->assertStringContainsString('data:image/png;base64,tecnico', $values['instalacion.firma_tecnico']);
        // Sin fotos: el bloque existe pero resuelve a vacío, no a un error.
        $this->assertSame('', $values['instalacion.fotos']);
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

        $values = $this->resolver->forInstallation($installation, collect(), 'data:image/png;base64,cliente', null);

        $this->assertSame('', $values['instalacion.firma_tecnico']);
    }

    /**
     * Un bloque que falla al renderizar (ej. $photos no es iterable) nunca
     * debe tumbar el documento completo — degrada a vacío y queda logueado,
     * como acordamos en el punto 4 de la revisión del diseño.
     */
    public function test_a_block_that_throws_while_rendering_degrades_to_empty_and_is_logged(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($message, $context) =>
                str_contains($message, 'instalacion.fotos')
                && $context['token'] === 'instalacion.fotos'
            );

        $tenant = Tenant::factory()->create();
        $customer = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
        $installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => '2026-07-25',
            'status'         => 'pendiente',
        ]);

        // `count($photos)` en el partial revienta con TypeError si $photos no
        // es Countable|array — forzamos exactamente ese caso.
        $notCountable = new \stdClass();

        $values = $this->resolver->forInstallation($installation, $notCountable, null, null);

        $this->assertSame('', $values['instalacion.fotos']);
    }
}
