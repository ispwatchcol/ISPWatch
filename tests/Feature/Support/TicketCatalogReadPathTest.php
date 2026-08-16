<?php

namespace Tests\Feature\Support;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketCatalogs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comportamiento del catálogo: vigencia, etiquetas y el endpoint que consume la SPA.
 *
 * NOTA HISTÓRICA — este archivo nació en la R2 con otra técnica. Contenía ocho
 * tests que CORROMPÍAN a propósito la columna enum (dejarla diciendo algo
 * distinto de lo que decía la clave foránea) para demostrar que ningún camino
 * de la aplicación la leía ya. Aquello era el detector que justificaba poder
 * eliminar esas columnas.
 *
 * La R3 las eliminó, así que la técnica se quedó sin sujeto: no hay espejo que
 * corromper. Esos ocho tests se retiraron y su cobertura vive ahora en
 * `TicketEnumColumnsDroppedTest`, que no simula el estado final — corre en él.
 *
 * Lo que queda aquí es lo que no dependía del espejo y sigue siendo el contrato
 * del catálogo.
 */
class TicketCatalogReadPathTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name' => 'Administrador', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $role->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Nubia', 'last_name' => 'Peláez', 'status' => true,
        ]);
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'subject'   => 'Servicio intermitente',
            'status'    => 'open',
            'priority'  => 'medium',
            'category'  => 'technical',
        ], $overrides));
    }

    // ── La validación sale del catálogo, no de una lista en el código ────

    #[Test]
    public function retirar_un_estado_del_catalogo_deja_de_aceptarlo_en_la_api(): void
    {
        $ticket = $this->ticket(['status' => 'open']);

        DB::table('ticket_status')->where('code', 'closed')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        // Las reglas se construyen desde los códigos VIGENTES, así que retirar
        // una fila deja de aceptarla sin tocar código ni desplegar.
        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Y lo que sigue vigente se sigue aceptando.
        $this->actingAs($this->staff)
            ->patchJson("/api/support/{$ticket->id}/status", ['status' => 'resolved'])
            ->assertOk();
    }

    #[Test]
    public function un_ticket_con_un_estado_ya_retirado_se_sigue_consultando_bien(): void
    {
        $ticket = $this->ticket(['status' => 'closed']);

        DB::table('ticket_status')->where('code', 'closed')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        // Es la razón de que el retiro sea suave y no un borrado: el histórico
        // no se toca. Un ticket de hace dos años tiene que poder seguir
        // diciendo en qué estado quedó aunque ese estado ya no se ofrezca.
        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('status_label', 'Cerrado');
    }

    // ── Etiquetas ────────────────────────────────────────────────────────

    #[Test]
    public function el_ticket_viaja_con_su_etiqueta_ademas_del_codigo(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress', 'priority' => 'urgent', 'category' => 'billing']);

        $this->actingAs($this->staff)
            ->getJson("/api/support/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('status_label', 'En progreso')
            ->assertJsonPath('priority_label', 'Urgente')
            ->assertJsonPath('category_label', 'Facturación');
    }

    // ── Endpoint de catálogos para la SPA ────────────────────────────────

    #[Test]
    public function el_endpoint_de_catalogos_entrega_codigo_y_etiqueta_ordenados(): void
    {
        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertSame(
            ['open', 'in_progress', 'resolved', 'closed'],
            collect($data['statuses'])->pluck('code')->all(),
            'El orden lo fija `weight`, no el orden de inserción.'
        );

        $this->assertSame('Abierto', $data['statuses'][0]['label']);
        $this->assertSame(['low', 'medium', 'high', 'urgent'], collect($data['priorities'])->pluck('code')->all());
        $this->assertSame(4, count($data['categories']));
        $this->assertSame(1, $data['versions']['status']);
    }

    #[Test]
    public function el_endpoint_de_catalogos_no_ofrece_filas_retiradas(): void
    {
        DB::table('ticket_priority')->where('code', 'urgent')->update(['valid_until' => now()->subDay()]);
        app(TicketCatalogs::class)->flush();

        $data = $this->actingAs($this->staff)->getJson('/api/catalogs/ticket')->assertOk()->json();

        $this->assertNotContains('urgent', collect($data['priorities'])->pluck('code')->all());
    }

    #[Test]
    public function el_endpoint_de_catalogos_exige_autenticacion(): void
    {
        $this->getJson('/api/catalogs/ticket')->assertUnauthorized();
    }
}
