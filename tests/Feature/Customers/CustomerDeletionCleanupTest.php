<?php

namespace Tests\Feature\Customers;

use App\Models\CustomerDocument;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MikroTik\CustomerDeprovisionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Borrar un cliente tiene que dejar CERO residuos: ni filas, ni archivos en
 * S3, ni configuración en el router. Confiar sólo en las claves foráneas en
 * cascada dejaba las tres cosas (ver App\Services\CustomerDeletionService).
 */
class CustomerDeletionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    /** El manager de red se sustituye por un doble: aquí no hay router real. */
    private function fakeDeprovision(bool $success = true): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(CustomerDeprovisionManager::class);
        $this->app->instance(CustomerDeprovisionManager::class, $mock);

        return $mock;
    }

    private function makeCustomer(array $profile = []): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create(array_merge([
            'user_id'        => $customer->id,
            'name'           => 'Cliente',
            'last_name'      => 'Prueba',
            'cedula'         => '123456',
            'address'        => 'Calle 1',
            'ip_user'        => '10.20.30.40',
            'pppoe_username' => 'cliente.prueba',
            'mac_address'    => 'AA:BB:CC:DD:EE:FF',
            'status'         => true,
        ], $profile));

        return $customer;
    }

    /** No hay RouterFactory: el router se crea a mano con lo mínimo. */
    private function makeRouter(): Router
    {
        return Router::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'Router de prueba',
            'ip'          => '172.18.1.2',
            'user_rb'     => 'admin',
            'password_rb' => 'secreta',
        ]);
    }

    public function test_it_deletes_the_s3_files_of_documents_and_installations(): void
    {
        $this->fakeDeprovision()->shouldReceive('purge')->andReturn(['success' => true, 'message' => 'ok']);
        Sanctum::actingAs($this->admin);

        $customer = $this->makeCustomer();

        Storage::disk('s3')->put('contratos/contrato.pdf', 'x');
        Storage::disk('s3')->put('actas/foto.jpg', 'x');
        Storage::disk('s3')->put('firmas/cliente.png', 'x');

        $installation = CustomerInstallation::create([
            'tenant_id'               => $this->tenant->id,
            'customer_id'             => $customer->id,
            'scheduled_date'          => now(),
            'address'                 => 'Calle 1',
            'status'                  => 'pendiente',
            'customer_signature_path' => 'firmas/cliente.png',
        ]);

        CustomerDocument::create([
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $customer->id,
            'type'        => 'contrato',
            'file_name'   => 'contrato.pdf',
            'file_path'   => 'contratos/contrato.pdf',
            'file_size'   => 1,
            'mime_type'   => 'application/pdf',
        ]);

        // Foto de instalación SIN customer_id: la columna es nullable desde que
        // las instalaciones pueden colgar de un prospecto. Filtrar sólo por
        // cliente la dejaría fuera, que es justo el residuo más numeroso.
        CustomerDocument::create([
            'tenant_id'       => $this->tenant->id,
            'customer_id'     => null,
            'installation_id' => $installation->id,
            'type'            => 'foto',
            'file_name'       => 'foto.jpg',
            'file_path'       => 'actas/foto.jpg',
            'file_size'       => 1,
            'mime_type'       => 'image/jpeg',
        ]);

        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(200);

        Storage::disk('s3')->assertMissing('contratos/contrato.pdf');
        Storage::disk('s3')->assertMissing('actas/foto.jpg');
        Storage::disk('s3')->assertMissing('firmas/cliente.png');
    }

    public function test_it_removes_the_rows_that_no_foreign_key_would_have_touched(): void
    {
        $this->fakeDeprovision()->shouldReceive('purge')->andReturn(['success' => true, 'message' => 'ok']);
        Sanctum::actingAs($this->admin);

        $customer = $this->makeCustomer();

        CustomerInstallation::create([
            'tenant_id'   => $this->tenant->id,
            'customer_id'    => $customer->id,
            'scheduled_date' => now(),
            'address'        => 'Calle 1',
            'status'         => 'pendiente',
        ]);

        DB::table('bulk_provision_runs')->insert([
            'id'          => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id'   => $this->tenant->id,
            'customer_id' => $customer->id,
            'status'      => 'done',
            'total'       => 1,
            'processed'   => 1,
            'results'     => json_encode([]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(200);

        $this->assertDatabaseMissing('customer_installations', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('bulk_provision_runs', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
        $this->assertDatabaseMissing('customer_profile', ['user_id' => $customer->id]);
    }

    /**
     * El prospecto es un registro comercial propio y sobrevive al cliente:
     * sólo se corta el vínculo, que es lo único que quedaría colgando.
     */
    public function test_it_unlinks_the_prospect_instead_of_deleting_it(): void
    {
        $this->fakeDeprovision()->shouldReceive('purge')->andReturn(['success' => true, 'message' => 'ok']);
        Sanctum::actingAs($this->admin);

        $customer = $this->makeCustomer();

        $prospectId = DB::table('prospects')->insertGetId([
            'tenant_id'         => $this->tenant->id,
            'name'             => 'Prospecto',
            'last_name'        => 'Convertido',
            'converted_user_id' => $customer->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(200);

        $this->assertDatabaseHas('prospects', ['id' => $prospectId, 'converted_user_id' => null]);
    }

    /**
     * Sin esto el cliente borrado sigue navegando: el secret PPPoE, la queue y
     * el lease se quedan en el equipo, y ya no queda ficha de dónde sacar la IP.
     */
    public function test_it_purges_the_customer_from_the_router_with_its_network_identity(): void
    {
        Sanctum::actingAs($this->admin);

        $router = $this->makeRouter();
        $customer = $this->makeCustomer(['router_id' => $router->id]);

        $this->fakeDeprovision()
            ->shouldReceive('purge')
            ->once()
            ->withArgs(function ($ip, $user, $pass, array $identity, $port) {
                return $identity['ip'] === '10.20.30.40'
                    && $identity['pppoe_username'] === 'cliente.prueba'
                    && $identity['mac_address'] === 'AA:BB:CC:DD:EE:FF';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(200);
    }

    /**
     * Un router caído no puede dejar clientes imposibles de borrar — pero
     * tampoco puede reportarse como si todo hubiera salido bien: la
     * configuración sigue en el equipo y alguien tiene que ir a quitarla.
     */
    public function test_a_router_failure_does_not_block_the_deletion_but_is_reported(): void
    {
        Sanctum::actingAs($this->admin);

        $router = $this->makeRouter();
        $customer = $this->makeCustomer(['router_id' => $router->id]);

        $this->fakeDeprovision()
            ->shouldReceive('purge')
            ->andReturn(['success' => false, 'message' => 'El router no respondió.']);

        $response = $this->deleteJson("/api/customers/{$customer->id}");

        $response->assertStatus(200);
        $this->assertStringContainsString('NO se pudo limpiar', $response->json('message'));
        $this->assertStringContainsString('El router no respondió.', $response->json('message'));
        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
    }

    public function test_a_customer_without_a_router_is_deleted_without_touching_the_network(): void
    {
        Sanctum::actingAs($this->admin);

        $this->fakeDeprovision()->shouldReceive('purge')->never();

        $customer = $this->makeCustomer(['router_id' => null]);

        $this->deleteJson("/api/customers/{$customer->id}")->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $customer->id]);
    }

    public function test_a_customer_from_another_tenant_is_never_deleted(): void
    {
        Sanctum::actingAs($this->admin);
        $this->fakeDeprovision()->shouldReceive('purge')->never();

        $otherTenant = Tenant::factory()->create();
        $other = User::factory()->create(['tenant_id' => $otherTenant->id]);
        CustomerProfile::create([
            'user_id'   => $other->id,
            'name'      => 'Otro',
            'last_name' => 'Tenant',
            'cedula'    => '999',
            'status'    => true,
        ]);

        $this->deleteJson("/api/customers/{$other->id}")->assertStatus(404);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }
}
