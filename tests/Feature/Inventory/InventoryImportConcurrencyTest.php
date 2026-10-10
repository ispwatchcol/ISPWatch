<?php

namespace Tests\Feature\Inventory;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dos cargas de inventario simultáneas de la MISMA empresa se corrompen entre sí.
 *
 * Por dos motivos independientes, y por eso el arreglo es un candado y no un
 * parche a uno de los dos:
 *
 *  1. `InventoryImport` precarga en memoria los seriales y MAC ya usados para no
 *     consultar por fila. Dos instancias en paralelo parten del mismo retrato y
 *     ninguna ve lo que inserta la otra: el mismo serial entra dos veces sin que
 *     la deduplicación se entere.
 *  2. `recordEntries()` reconoce las filas recién insertadas por
 *     `id > max(id) previo`. Si la otra importación confirma su lote en medio,
 *     esas filas caen dentro del rango y el kardex les atribuye una entrada
 *     ajena.
 *
 * Serializar por empresa cierra los dos. Estas pruebas fijan el contrato: la
 * segunda carga se rechaza con 409 y un mensaje que se entiende, en vez de
 * "funcionar" y dejar el inventario descuadrado. P-19 · KAN-77.
 */
class InventoryImportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name'        => 'Admin',
            'permissions' => ['*'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function clave(int $tenantId): string
    {
        return "inventory-import:tenant:{$tenantId}";
    }

    private function archivo(): UploadedFile
    {
        return UploadedFile::fake()->create('inventario.xlsx', 10);
    }

    #[Test]
    public function rechaza_una_segunda_carga_mientras_hay_una_en_curso(): void
    {
        // Simula la carga que ya está corriendo: el candado está tomado.
        $lock = Cache::lock($this->clave($this->tenant->id), 300);
        $this->assertTrue($lock->get(), 'El candado debería poder tomarse la primera vez.');

        $this->postJson('/api/import/inventory', ['file' => $this->archivo()])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('summary.equipos', 0);

        $lock->release();
    }

    #[Test]
    public function el_mensaje_del_rechazo_explica_que_hacer(): void
    {
        // Un 409 sin texto útil deja al usuario reintentando a ciegas.
        $lock = Cache::lock($this->clave($this->tenant->id), 300);
        $lock->get();

        $mensaje = $this->postJson('/api/import/inventory', ['file' => $this->archivo()])
            ->assertStatus(409)
            ->json('message');

        $this->assertStringContainsString('en curso', $mensaje);
        $this->assertStringContainsString('Espera', $mensaje);

        $lock->release();
    }

    #[Test]
    public function el_candado_es_por_empresa_y_no_frena_a_otra(): void
    {
        // Importante: el bloqueo no puede convertirse en una cola global. Una
        // empresa cargando inventario no debe frenar a las demás.
        $otro = Tenant::factory()->create();

        $lock = Cache::lock($this->clave($otro->id), 300);
        $lock->get();

        // La empresa del usuario autenticado tiene su propio candado libre, así
        // que su carga NO se rechaza por concurrencia. Lo único que importa acá
        // es que NO sea 409: el archivo de prueba no es un xlsx real y falla más
        // adelante, pero ese fallo es de contenido, no de bloqueo.
        $respuesta = $this->postJson('/api/import/inventory', ['file' => $this->archivo()]);

        $this->assertNotSame(
            409,
            $respuesta->status(),
            'El candado de otra empresa no debe bloquear esta carga.'
        );

        $lock->release();
    }

    #[Test]
    public function el_candado_se_suelta_aunque_la_importacion_reviente(): void
    {
        // Un archivo inválido hace que Excel lance. Si el candado no se soltara
        // en el finally, la empresa quedaría bloqueada hasta que expire el TTL.
        $this->postJson('/api/import/inventory', ['file' => $this->archivo()]);

        $lock = Cache::lock($this->clave($this->tenant->id), 10);
        $this->assertTrue(
            $lock->get(),
            'Tras una importación fallida el candado debe quedar libre.'
        );
        $lock->release();
    }
}
