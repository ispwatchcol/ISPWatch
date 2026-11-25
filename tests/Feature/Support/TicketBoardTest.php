<?php

namespace Tests\Feature\Support;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La lista de tickets como cola de trabajo (bitácora § 133): pestañas,
 * contadores, búsqueda y paginación en el servidor — sin romper la respuesta
 * de siempre de `GET /support`.
 */
class TicketBoardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;
    private User $yo;
    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        Role::create(['name' => 'Superadmin global', 'code' => 'superadmin', 'permissions' => ['*'], 'tenant_id' => null]);

        $rol = Role::create([
            'name' => 'Técnico', 'code' => 'staff',
            'permissions' => [Permissions::TICKET_VIEW], 'tenant_id' => $this->tenant->id,
        ]);

        $this->yo   = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);
        $this->otro = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rol->id]);

        $this->customer = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'user_name' => 'Marta', 'user_lastname' => 'Gómez', 'tel' => '3001234567',
        ]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Marta', 'last_name' => 'Gómez',
            'address' => 'Vereda La Esperanza km 3', 'status' => true,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function ticket(string $estado, array $extra = []): SupportTicket
    {
        return SupportTicket::create($extra + [
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Sin internet', 'status' => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    /** @return array<string, mixed> */
    private function tablero(string $query = ''): array
    {
        return $this->actingAs($this->yo)->getJson('/api/support?page=1' . $query)->assertOk()->json();
    }

    #[Test]
    public function por_defecto_muestra_los_abiertos_y_cuenta_cada_pestana(): void
    {
        $this->ticket(TicketWorkflow::RADICADO);
        $this->ticket(TicketWorkflow::EN_INTERVENCION, ['staff_id' => $this->yo->id]);
        $this->ticket(TicketWorkflow::PENDIENTE_MATERIAL, ['staff_id' => $this->otro->id]);
        $this->ticket(TicketWorkflow::SERVICIO_RESTABLECIDO, ['staff_id' => $this->yo->id]);
        $this->ticket(TicketWorkflow::CERRADO, ['staff_id' => $this->yo->id]);

        $datos = $this->tablero();

        $this->assertSame('open', $datos['tab']);
        $this->assertSame(4, $datos['total'], 'El cerrado no entra en «Abiertos».');

        $this->assertSame([
            'open' => 4, 'mine' => 2, 'unassigned' => 1, 'new' => 1, 'in_progress' => 1,
            'waiting' => 1, 'to_close' => 1, 'closed' => 1, 'all' => 5,
        ], $datos['counts']);
    }

    #[Test]
    public function cada_pestana_filtra_lo_suyo(): void
    {
        $nuevo     = $this->ticket(TicketWorkflow::RADICADO);
        $mio       = $this->ticket(TicketWorkflow::ASIGNADO, ['staff_id' => $this->yo->id]);
        $espera    = $this->ticket(TicketWorkflow::PENDIENTE_CLIENTE, ['staff_id' => $this->otro->id]);
        $paraCerrar = $this->ticket(TicketWorkflow::EN_OBSERVACION, ['staff_id' => $this->otro->id]);
        $cerrado   = $this->ticket(TicketWorkflow::CERRADO);

        $ids = fn (string $tab) => array_column($this->tablero("&tab={$tab}")['data'], 'id');

        $this->assertSame([$mio->id], $ids('mine'));
        $this->assertSame([$nuevo->id], $ids('unassigned'));
        $this->assertSame([$espera->id], $ids('waiting'));
        $this->assertSame([$paraCerrar->id], $ids('to_close'));
        $this->assertSame([$cerrado->id], $ids('closed'));
    }

    #[Test]
    public function lo_urgente_va_primero_y_a_igual_urgencia_lo_mas_viejo(): void
    {
        $viejo   = $this->ticket(TicketWorkflow::RADICADO, ['created_at' => now()->subDays(3)]);
        $nuevo   = $this->ticket(TicketWorkflow::RADICADO, ['created_at' => now()->subHour()]);
        $urgente = $this->ticket(TicketWorkflow::RADICADO, ['priority' => 'urgent', 'created_at' => now()]);

        $this->assertSame([$urgente->id, $viejo->id, $nuevo->id], array_column($this->tablero()['data'], 'id'));

        // «Más recientes» deja el orden de siempre.
        $this->assertSame(
            [$urgente->id, $nuevo->id, $viejo->id],
            array_column($this->tablero('&sort=recent')['data'], 'id'),
        );
    }

    #[Test]
    public function busca_por_numero_telefono_nombre_completo_y_direccion(): void
    {
        $buscado = $this->ticket(TicketWorkflow::RADICADO);

        $otroCliente = User::factory()->create(['tenant_id' => $this->tenant->id, 'user_name' => 'Pedro', 'user_lastname' => 'Rojas']);
        CustomerProfile::create(['user_id' => $otroCliente->id, 'name' => 'Pedro', 'last_name' => 'Rojas', 'status' => true]);
        $this->ticket(TicketWorkflow::RADICADO, ['user_id' => $otroCliente->id, 'subject' => 'Lento']);

        foreach (["#{$buscado->id}", '3001234567', 'marta gómez', 'esperanza'] as $termino) {
            $ids = array_column($this->tablero('&search=' . urlencode($termino))['data'], 'id');
            $this->assertSame([$buscado->id], $ids, "Buscando «{$termino}».");
        }
    }

    #[Test]
    public function la_fila_trae_lo_necesario_y_el_grupo_del_estado(): void
    {
        $this->ticket(TicketWorkflow::PENDIENTE_MATERIAL, ['staff_id' => $this->yo->id]);

        $fila = $this->tablero()['data'][0];

        $this->assertSame('en_espera', $fila['status_group']);
        $this->assertSame('Vereda La Esperanza km 3', $fila['customer_address']);
        $this->assertSame('3001234567', $fila['user']['tel']);
        $this->assertSame($this->yo->id, $fila['staff']['id']);
        $this->assertArrayNotHasKey('messages', $fila, 'La cola no arrastra notas ni adjuntos.');
    }

    #[Test]
    public function en_el_simple_la_fila_usa_el_nombre_corto(): void
    {
        $this->tenant->forceFill(['ticket_workflow_mode' => 'simple'])->save();
        $this->ticket(TicketWorkflow::ASIGNADO);

        $datos = $this->tablero();

        $this->assertSame('simple', $datos['workflow_mode']);
        $this->assertSame('En proceso', $datos['data'][0]['status_label']);
    }

    #[Test]
    public function no_muestra_ni_cuenta_tickets_de_otro_isp(): void
    {
        $this->ticket(TicketWorkflow::RADICADO);

        $ajeno = Tenant::factory()->create();
        $clienteAjeno = User::factory()->create(['tenant_id' => $ajeno->id]);
        SupportTicket::create([
            'tenant_id' => $ajeno->id, 'user_id' => $clienteAjeno->id,
            'subject' => 'De otro ISP', 'status' => TicketWorkflow::RADICADO, 'priority' => 'medium', 'category' => 'technical',
        ]);

        $datos = $this->tablero('&tab=all');

        $this->assertSame(1, $datos['total']);
        $this->assertSame(1, $datos['counts']['all']);
    }

    #[Test]
    public function pagina_en_el_servidor(): void
    {
        foreach (range(1, 7) as $i) {
            $this->ticket(TicketWorkflow::RADICADO, ['subject' => "T{$i}"]);
        }

        $datos = $this->tablero('&per_page=5');

        $this->assertCount(5, $datos['data']);
        $this->assertSame(2, $datos['last_page']);
        $this->assertSame(7, $datos['total']);
    }

    #[Test]
    public function sin_page_ni_tab_responde_el_arreglo_de_siempre(): void
    {
        $this->ticket(TicketWorkflow::RADICADO);
        $this->ticket(TicketWorkflow::CERRADO);

        $filas = $this->actingAs($this->yo)->getJson('/api/support')->assertOk()->json();

        $this->assertIsList($filas);
        $this->assertCount(2, $filas, 'El listado de siempre sigue trayendo también los cerrados.');
    }
}
