<?php

namespace Tests\Feature\Sectorial;

use App\Models\Role;
use App\Models\Sectorial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La contraseña del RouterBoard de un elemento de red no sale en ninguna
 * respuesta de la API —el listado lo usan los selectores de soporte y de
 * clientes—, y editar el elemento sin escribir contraseña conserva la guardada.
 * Mismo arreglo que `RouterCredentialsHiddenTest` (P-2 / KAN-45).
 */
class SectorialCredentialsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'clave-del-rb-sectorial';

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // CheckPermission deja pasar todo role_id = 1: se ocupa antes.
        Role::create(['name' => 'Administrador', 'permissions' => ['*']]);
        $this->tenant = Tenant::factory()->create();
    }

    private function actingWith(array $permissions): void
    {
        $role = Role::create(['name' => 'Rol' . (++$this->seq), 'permissions' => $permissions]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]));
    }

    private function sectorial(?string $password = self::SECRET): Sectorial
    {
        return Sectorial::create([
            'tenant_id'    => $this->tenant->id,
            'name'         => 'AP Norte ' . (++$this->seq),
            'element_type' => 'sectorial',
            'ip'           => '10.20.0.2',
            'user_rb'      => 'admin',
            'pass_rb'      => $password,
        ]);
    }

    private function assertNoSecret(string $json): void
    {
        $this->assertStringNotContainsString(self::SECRET, $json, 'La respuesta filtra la contraseña del RB.');
        $this->assertStringNotContainsString('"pass_rb"', $json, 'La respuesta trae la clave pass_rb.');
    }

    #[Test]
    public function el_listado_que_usan_soporte_y_clientes_no_trae_la_contrasena(): void
    {
        // Un rol de soporte sin acceso a Sectoriales: es quien la veía sin deber.
        $this->actingWith(['view_support']);
        $this->sectorial();

        $response = $this->getJson('/api/sectorials')->assertOk();

        $this->assertNoSecret($response->getContent());
        $this->assertTrue($response->json('0.has_pass_rb'));
    }

    #[Test]
    public function el_detalle_tampoco_pero_dice_si_hay_contrasena(): void
    {
        $this->actingWith(['view_sectorials']);
        $conClave = $this->sectorial();
        $sinClave = $this->sectorial(null);

        $detalle = $this->getJson("/api/sectorials/{$conClave->id}")->assertOk();
        $this->assertNoSecret($detalle->getContent());
        $this->assertStringContainsString('admin', $detalle->getContent());
        $this->assertTrue($detalle->json('has_pass_rb') ?? $detalle->json('sectorial.has_pass_rb'));

        $vacio = $this->getJson("/api/sectorials/{$sinClave->id}")->assertOk();
        $this->assertFalse($vacio->json('has_pass_rb') ?? $vacio->json('sectorial.has_pass_rb'));
    }

    #[Test]
    public function la_respuesta_de_alta_y_de_edicion_tampoco(): void
    {
        $this->actingWith(['view_sectorials']);

        $alta = $this->postJson('/api/sectorials', [
            'name' => 'AP Sur', 'element_type' => 'sectorial', 'user_rb' => 'admin', 'pass_rb' => self::SECRET,
        ])->assertCreated();
        $this->assertNoSecret($alta->getContent());

        $edicion = $this->putJson("/api/sectorials/{$this->sectorial()->id}", ['name' => 'AP Renombrado'])->assertOk();
        $this->assertNoSecret($edicion->getContent());
    }

    #[Test]
    public function editar_con_la_contrasena_en_blanco_conserva_la_guardada(): void
    {
        // Lo que manda un SectorialEdit.vue viejo, en caché durante el
        // despliegue: el campo vacío porque la API ya no lo prellena.
        $this->actingWith(['view_sectorials']);
        $sectorial = $this->sectorial();

        $this->putJson("/api/sectorials/{$sectorial->id}", [
            'name' => 'AP Renombrado', 'user_rb' => 'admin', 'pass_rb' => '',
        ])->assertOk();

        $sectorial->refresh();
        $this->assertSame('AP Renombrado', $sectorial->name);
        $this->assertSame(self::SECRET, $sectorial->pass_rb);
    }

    #[Test]
    public function escribir_una_contrasena_nueva_la_reemplaza(): void
    {
        $this->actingWith(['view_sectorials']);
        $sectorial = $this->sectorial();

        $this->putJson("/api/sectorials/{$sectorial->id}", ['pass_rb' => 'otra-clave'])->assertOk();

        $this->assertSame('otra-clave', $sectorial->fresh()->pass_rb);
    }
}
