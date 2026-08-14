<?php

namespace Tests\Feature\Documents;

use App\Mail\ContractSignatureLinkMail;
use App\Models\ContractSignatureLink;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Firma remota del contrato: el cliente firma desde un link, sin cuenta.
 *
 * Lo que se protege aquí no es la maquetación sino la autorización: estas
 * rutas son las ÚNICAS del sistema que escriben un documento legal sin usuario
 * autenticado, y el token es lo único que se interpone.
 */
class RemoteContractSigningTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Tenant $tenant;
    protected User $staff;
    protected User $customer;
    protected CustomerProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');
        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
            'email'         => 'juan@example.com',
            'tel'           => '3001234567',
        ]);

        $this->profile = CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '1234567890',
            'address'   => 'Calle Falsa 123',
        ]);

        Plan::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    /** Emite un link y devuelve el token en claro (sólo existe en esa respuesta). */
    private function issueToken(string $channel = 'manual'): string
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-links", [
            'channel' => $channel,
        ])->assertStatus(201);

        // El token viaja dentro de la URL de firma, nunca como campo suelto.
        $url = $response->json('url');
        $token = substr($url, strrpos($url, '/') + 1);

        // Las rutas públicas se prueban SIN sesión: si el actingAs siguiera
        // activo, un fallo de autorización pasaría inadvertido.
        app('auth')->forgetGuards();

        return $token;
    }

    // ─── Emisión del link ────────────────────────────────────────────────

    public function test_issuing_a_link_never_stores_the_token_in_clear(): void
    {
        $token = $this->issueToken();

        $link = ContractSignatureLink::first();

        $this->assertNotNull($link);
        $this->assertNotSame($token, $link->token_hash);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertDatabaseMissing('contract_signature_links', ['token_hash' => $token]);
    }

    public function test_issuing_a_new_link_revokes_the_previous_one(): void
    {
        $first = $this->issueToken();
        $second = $this->issueToken();

        $this->getJson("/api/public/contract/{$first}")->assertJsonPath('status', 'revoked');
        $this->getJson("/api/public/contract/{$second}")->assertJsonPath('status', 'pending');
    }

    public function test_email_channel_sends_the_link_to_the_customer(): void
    {
        $this->issueToken('email');

        Mail::assertSent(ContractSignatureLinkMail::class, fn ($mail) => $mail->hasTo('juan@example.com'));

        $this->assertNotNull(ContractSignatureLink::first()->sent_at);
    }

    public function test_whatsapp_channel_returns_a_deep_link_with_the_country_code(): void
    {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/customers/{$this->customer->id}/contract-links", [
            'channel' => 'whatsapp',
        ])->assertStatus(201);

        $this->assertStringStartsWith('https://wa.me/573001234567?text=', $response->json('whatsapp_url'));
        // El servidor no envió nada: marcarlo como enviado ensuciaría la constancia.
        $this->assertNull(ContractSignatureLink::first()->sent_at);
    }

    public function test_cannot_issue_a_link_for_a_customer_of_another_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $otherCustomer = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->postJson("/api/customers/{$otherCustomer->id}/contract-links")->assertStatus(404);
    }

    public function test_cannot_issue_a_link_when_the_contract_is_already_signed(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ])->assertStatus(201);

        $this->postJson("/api/customers/{$this->customer->id}/contract-links")
            ->assertStatus(409);
    }

    public function test_the_link_listing_never_exposes_the_token(): void
    {
        $this->issueToken();

        Sanctum::actingAs($this->staff);

        $response = $this->getJson("/api/customers/{$this->customer->id}/contract-links")
            ->assertStatus(200);

        $this->assertArrayNotHasKey('token_hash', $response->json()[0]);
        $this->assertArrayNotHasKey('token', $response->json()[0]);
    }

    // ─── Portada pública ─────────────────────────────────────────────────

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->getJson('/api/public/contract/no-existe')->assertStatus(404);
    }

    /**
     * Quien tenga el link pero no la cédula no puede cosechar datos del
     * cliente: la portada sólo dice el nombre de pila y el del ISP.
     */
    public function test_the_landing_page_does_not_leak_personal_data_before_verification(): void
    {
        $token = $this->issueToken();

        $response = $this->getJson("/api/public/contract/{$token}")->assertStatus(200);

        $response->assertJsonPath('status', 'pending')
            ->assertJsonPath('customer_first_name', 'Juan')
            ->assertJsonPath('requires_verification', true);

        $body = $response->getContent();
        $this->assertStringNotContainsString('1234567890', $body);
        $this->assertStringNotContainsString('Calle Falsa', $body);
    }

    public function test_opening_the_link_records_the_first_open_only(): void
    {
        $token = $this->issueToken();

        $this->getJson("/api/public/contract/{$token}")->assertStatus(200);
        $firstOpen = ContractSignatureLink::first()->opened_at;
        $this->assertNotNull($firstOpen);

        $this->travel(5)->minutes();
        $this->getJson("/api/public/contract/{$token}")->assertStatus(200);

        $this->assertEquals($firstOpen, ContractSignatureLink::first()->opened_at);
    }

    // ─── Verificación ────────────────────────────────────────────────────

    public function test_verification_returns_the_contract_when_the_digits_match(): void
    {
        $token = $this->issueToken();

        $response = $this->postJson("/api/public/contract/{$token}/verify", [
            'document_last4' => '7890',
        ])->assertStatus(200);

        $response->assertJsonPath('status', 'verified')
            ->assertJsonPath('customer.cedula', '1234567890');

        $this->assertStringContainsString('CONTRATO', $response->json('contract_html'));
    }

    public function test_wrong_digits_are_counted_and_eventually_lock_the_link(): void
    {
        $token = $this->issueToken();

        for ($i = 1; $i <= ContractSignatureLink::MAX_FAILED_ATTEMPTS; $i++) {
            $this->postJson("/api/public/contract/{$token}/verify", ['document_last4' => '0000'])
                ->assertStatus(422);
        }

        $this->assertSame(ContractSignatureLink::MAX_FAILED_ATTEMPTS, ContractSignatureLink::first()->failed_attempts);

        // Quemado: ni siquiera con los dígitos correctos.
        $this->postJson("/api/public/contract/{$token}/verify", ['document_last4' => '7890'])
            ->assertStatus(409)
            ->assertJsonPath('status', 'locked');
    }

    // ─── Firma ───────────────────────────────────────────────────────────

    public function test_signing_through_the_link_stores_a_signed_contract_and_burns_the_link(): void
    {
        $token = $this->issueToken();

        $response = $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(201);

        $document = CustomerDocument::where('customer_id', $this->customer->id)->first();

        $this->assertNotNull($document);
        $this->assertTrue($document->signed);
        $this->assertSame('contrato', $document->type);
        $this->assertSame($response->json('contract_number'), $document->contract_number);

        $bytes = Storage::disk('s3')->get($document->file_path);
        $this->assertStringStartsWith('%PDF-', $bytes);

        // La huella tiene que corresponder al archivo REALMENTE almacenado:
        // es lo único que permite demostrar años después que el PDF que se
        // exhibe es el que se firmó.
        $this->assertSame(hash('sha256', $bytes), $document->content_sha256);

        $link = ContractSignatureLink::first();
        $this->assertNotNull($link->signed_at);
        $this->assertSame($document->id, $link->document_id);
        $this->assertNotNull($link->signer_ip);

        // Un solo uso.
        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(409);
    }

    /**
     * El paso de verificación no puede saltarse llamando directo a /sign: es
     * la única barrera de identidad que tiene el flujo remoto.
     */
    public function test_signing_without_the_verification_digits_is_rejected(): void
    {
        $token = $this->issueToken();

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature' => self::SAMPLE_PNG,
            'accepted'  => true,
        ])->assertStatus(422);

        $this->assertDatabaseCount('customer_documents', 0);
    }

    public function test_signing_requires_explicit_acceptance(): void
    {
        $token = $this->issueToken();

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
        ])->assertStatus(422);

        $this->assertDatabaseCount('customer_documents', 0);
    }

    public function test_a_rejected_signature_never_consumes_a_contract_number(): void
    {
        $token = $this->issueToken();
        $before = $this->tenant->fresh()->next_contract_number;

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '0000',
            'accepted'       => true,
        ])->assertStatus(422);

        $this->assertSame($before, $this->tenant->fresh()->next_contract_number);
    }

    public function test_an_expired_link_cannot_sign(): void
    {
        $token = $this->issueToken();

        ContractSignatureLink::first()->forceFill(['expires_at' => now()->subHour()])->save();

        $this->getJson("/api/public/contract/{$token}")->assertJsonPath('status', 'expired');

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(409);

        $this->assertDatabaseCount('customer_documents', 0);
    }

    public function test_a_revoked_link_cannot_sign(): void
    {
        $token = $this->issueToken();

        Sanctum::actingAs($this->staff);
        $this->deleteJson('/api/customers/contract-links/' . ContractSignatureLink::first()->id)
            ->assertStatus(200);
        app('auth')->forgetGuards();

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(409);
    }

    /**
     * El PDF firmado a distancia lleva impresa la constancia (fecha, IP,
     * dispositivo). Es lo que lo hace oponible: sin ella el documento no se
     * distingue de uno que el ISP hubiera generado por su cuenta.
     */
    public function test_the_remote_contract_pdf_carries_the_signature_audit_stamp(): void
    {
        $token = $this->issueToken();

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(201);

        $document = CustomerDocument::where('customer_id', $this->customer->id)->first();
        $link = ContractSignatureLink::first();

        // dompdf comprime los flujos del PDF, así que el texto no se puede
        // buscar en los bytes. Se comprueba sobre el HTML que lo produce.
        $html = view('documents.blocks.signature_audit', [
            'audit' => [
                'link_id'    => $link->id,
                'signed_at'  => $link->signed_at->format('d/m/Y H:i:s'),
                'timezone'   => config('app.timezone'),
                'ip'         => $link->signer_ip,
                'user_agent' => '—',
                'sent_to'    => null,
                'opened_at'  => null,
            ],
        ])->render();

        $this->assertStringContainsString('CONSTANCIA DE FIRMA ELECTRÓNICA', $html);
        $this->assertStringContainsString((string) $link->signer_ip, $html);
        $this->assertNotNull($document->content_sha256);
    }

    /**
     * La firma presencial NO lleva constancia: la presencia un empleado y sus
     * contratos deben salir exactamente igual que antes de que existiera el
     * flujo remoto.
     */
    public function test_the_onsite_contract_has_no_audit_stamp(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ])->assertStatus(201);

        $this->assertDatabaseCount('contract_signature_links', 0);
    }

    /**
     * Un cliente sin cédula registrada no puede quedar encerrado fuera de su
     * propio contrato: sin dato con el que verificar, se le deja pasar.
     */
    public function test_a_customer_without_cedula_skips_verification(): void
    {
        $this->profile->forceFill(['cedula' => null])->save();

        $token = $this->issueToken();

        $this->getJson("/api/public/contract/{$token}")
            ->assertJsonPath('requires_verification', false);

        $this->postJson("/api/public/contract/{$token}/verify", [])->assertStatus(200);

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature' => self::SAMPLE_PNG,
            'accepted'  => true,
        ])->assertStatus(201);
    }

    /**
     * Si el ISP firmó presencialmente entre medias, el link tiene que morir en
     * vez de generar un segundo contrato con otro consecutivo.
     */
    public function test_a_link_dies_if_the_contract_gets_signed_on_site_meanwhile(): void
    {
        $token = $this->issueToken();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/customers/{$this->customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ])->assertStatus(201);
        app('auth')->forgetGuards();

        $this->postJson("/api/public/contract/{$token}/sign", [
            'signature'      => self::SAMPLE_PNG,
            'document_last4' => '7890',
            'accepted'       => true,
        ])->assertStatus(409);

        $this->assertSame(1, CustomerDocument::where('type', 'contrato')->count());
        $this->assertNotNull(ContractSignatureLink::first()->revoked_at);
    }

    // ─── Recordatorio ────────────────────────────────────────────────────

    public function test_the_reminder_command_issues_a_fresh_link_and_only_nags_once(): void
    {
        $this->issueToken('email');
        Mail::fake();

        $this->travel(25)->hours();

        $this->artisan('contracts:remind-unsigned')->assertExitCode(0);

        Mail::assertSent(ContractSignatureLinkMail::class, fn ($mail) => $mail->isReminder === true);

        // El link viejo quedó revocado y el nuevo trae el sello del aviso.
        $fresh = ContractSignatureLink::usable()->first();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->reminder_sent_at);

        // Segunda pasada: no se insiste.
        Mail::fake();
        $this->artisan('contracts:remind-unsigned')->assertExitCode(0);
        Mail::assertNothingSent();
    }
}
