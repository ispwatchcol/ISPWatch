<?php

namespace Tests\Feature\Sectorial;

use App\Models\Role;
use App\Models\Sectorial;
use App\Models\SectorialPhoto;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-40 / KAN-96: las fotos de sectorial se guardan en `s3` y se entregan por un
 * endpoint autenticado que comprueba el tenant, igual que los adjuntos de ticket.
 */
class SectorialPhotoDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Storage::fake('public');

        // CheckPermission deja pasar todo role_id = 1. Se ocupa ese id antes,
        // para que ningún rol de estas pruebas sea superadmin sin querer.
        Role::create(['name' => 'Administrador', 'permissions' => ['*']]);
    }

    private function userWith(Tenant $tenant, array $permissions): User
    {
        $role = Role::create(['name' => 'Rol' . (++$this->seq), 'permissions' => $permissions]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
    }

    private function sectorial(Tenant $tenant): Sectorial
    {
        return Sectorial::create([
            'tenant_id'    => $tenant->id,
            'name'         => 'Sector ' . (++$this->seq),
            'element_type' => 'sectorial',
        ]);
    }

    /** Fila de foto ya guardada, con su archivo en el disco indicado. */
    private function photo(Sectorial $sectorial, string $disk = 's3', array $overrides = []): SectorialPhoto
    {
        $path = "sectorial_photos/{$sectorial->id}/foto{$this->seq}.jpg";
        Storage::disk($disk)->put($path, 'bytes-de-imagen');

        return SectorialPhoto::withoutGlobalScopes()->create(array_merge([
            'sectorial_id' => $sectorial->id,
            'tenant_id'    => $sectorial->tenant_id,
            'file_name'    => 'torre.jpg',
            'file_path'    => $path,
            'file_size'    => 15,
            'mime_type'    => 'image/jpeg',
        ], $overrides));
    }

    private function showUrl(Sectorial $sectorial, SectorialPhoto $photo): string
    {
        return "/api/sectorials/{$sectorial->id}/photos/{$photo->id}";
    }

    // ── Subida ─────────────────────────────────────────────────

    #[Test]
    public function la_foto_se_sube_al_disco_s3_y_su_url_es_el_endpoint_autenticado(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $response = $this->post("/api/sectorials/{$sectorial->id}/photos", [
            'photos' => [UploadedFile::fake()->create('torre.jpg', 20, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $photo = SectorialPhoto::withoutGlobalScopes()->firstOrFail();
        Storage::disk('s3')->assertExists($photo->file_path);
        Storage::disk('public')->assertMissing($photo->file_path);

        $url = $response->json('photos.0.url');
        $this->assertStringEndsWith("/api/sectorials/{$sectorial->id}/photos/{$photo->id}", $url);
        $this->assertStringNotContainsString('/storage/', $url);
    }

    // ── Entrega ────────────────────────────────────────────────

    #[Test]
    public function quien_ve_el_sectorial_recibe_la_foto_en_linea_y_sin_cache_compartida(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $response = $this->get($this->showUrl($sectorial, $photo))->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function soporte_tambien_la_ve_igual_que_el_listado(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);
        Sanctum::actingAs($this->userWith($tenant, ['view_support']));

        $this->get($this->showUrl($sectorial, $photo))->assertOk();
    }

    #[Test]
    public function sin_sesion_no_se_entrega(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);

        $this->getJson($this->showUrl($sectorial, $photo))->assertUnauthorized();
    }

    #[Test]
    public function sin_permiso_no_se_entrega(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);
        Sanctum::actingAs($this->userWith($tenant, ['view_billing']));

        $this->getJson($this->showUrl($sectorial, $photo))->assertForbidden();
    }

    #[Test]
    public function la_foto_de_otro_isp_da_404(): void
    {
        $propio = Tenant::factory()->create();
        $ajeno  = Tenant::factory()->create();
        $sectorialAjeno = $this->sectorial($ajeno);
        $photoAjena     = $this->photo($sectorialAjeno);
        Sanctum::actingAs($this->userWith($propio, ['view_sectorials']));

        $this->getJson($this->showUrl($sectorialAjeno, $photoAjena))->assertNotFound();
    }

    #[Test]
    public function colgar_una_foto_ajena_de_un_sectorial_propio_da_404(): void
    {
        $propio = Tenant::factory()->create();
        $ajeno  = Tenant::factory()->create();
        $sectorialPropio = $this->sectorial($propio);
        $photoAjena      = $this->photo($this->sectorial($ajeno));
        Sanctum::actingAs($this->userWith($propio, ['view_sectorials']));

        $this->getJson("/api/sectorials/{$sectorialPropio->id}/photos/{$photoAjena->id}")->assertNotFound();
    }

    #[Test]
    public function un_tipo_fuera_de_la_lista_blanca_se_descarga_nunca_en_linea(): void
    {
        // mime_type se guardó al subir y no se vuelve a verificar: servir
        // text/html en línea desde nuestro dominio sería un XSS almacenado.
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial, 's3', ['mime_type' => 'text/html', 'file_name' => 'x.html']);
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $response = $this->get($this->showUrl($sectorial, $photo))->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function una_foto_antigua_del_disco_public_se_sigue_sirviendo(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial, 'public');
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $this->get($this->showUrl($sectorial, $photo))->assertOk();
    }

    #[Test]
    public function si_el_archivo_ya_no_existe_responde_404_con_mensaje(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);
        Storage::disk('s3')->delete($photo->file_path);
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $this->getJson($this->showUrl($sectorial, $photo))
            ->assertNotFound()
            ->assertJson(['message' => 'La foto ya no está disponible.']);
    }

    // ── Borrado ────────────────────────────────────────────────

    #[Test]
    public function borrar_la_foto_quita_el_archivo_de_s3(): void
    {
        $tenant    = Tenant::factory()->create();
        $sectorial = $this->sectorial($tenant);
        $photo     = $this->photo($sectorial);
        Sanctum::actingAs($this->userWith($tenant, ['view_sectorials']));

        $this->deleteJson($this->showUrl($sectorial, $photo))->assertOk();

        Storage::disk('s3')->assertMissing($photo->file_path);
        $this->assertNull(SectorialPhoto::withoutGlobalScopes()->find($photo->id));
    }
}
