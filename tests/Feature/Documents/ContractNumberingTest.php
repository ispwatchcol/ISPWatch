<?php

namespace Tests\Feature\Documents;

use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractNumberService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consecutivo de los contratos de servicio: cada contrato firmado recibe un
 * número irrepetible dentro del tenant, con el prefijo que el ISP configuró,
 * y ese número queda impreso dentro del PDF.
 */
class ContractNumberingTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Tenant $tenant;
    protected User $staff;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = $this->makeCustomer($this->tenant);
    }

    private function makeCustomer(Tenant $tenant): User
    {
        $customer = User::factory()->create([
            'tenant_id'     => $tenant->id,
            'user_name'     => 'Juan',
            'user_lastname' => 'Pérez',
        ]);

        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'cedula'    => '123456789',
            'address'   => 'Calle Falsa 123',
        ]);

        return $customer;
    }

    private function sign(User $customer): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/customers/{$customer->id}/contract-sign", [
            'signature' => self::SAMPLE_PNG,
        ]);
    }

    public function test_the_first_signed_contract_gets_number_one_with_the_default_prefix(): void
    {
        Sanctum::actingAs($this->staff);

        $this->sign($this->customer)
            ->assertStatus(201)
            ->assertJsonPath('document.contract_number', 'CTR-00001');

        $this->assertDatabaseHas('customer_documents', [
            'customer_id'     => $this->customer->id,
            'type'            => 'contrato',
            'contract_number' => 'CTR-00001',
        ]);
    }

    public function test_uses_the_prefix_configured_by_the_tenant(): void
    {
        $this->tenant->update(['contract_prefix' => 'FIBRAX']);
        Sanctum::actingAs($this->staff);

        $this->sign($this->customer)
            ->assertStatus(201)
            ->assertJsonPath('document.contract_number', 'FIBRAX-00001');
    }

    public function test_the_sequence_advances_and_never_repeats_within_a_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $second = $this->makeCustomer($this->tenant);
        $third  = $this->makeCustomer($this->tenant);

        $this->sign($this->customer)->assertStatus(201);
        $this->sign($second)->assertStatus(201);
        $this->sign($third)->assertStatus(201);

        $numbers = CustomerDocument::where('tenant_id', $this->tenant->id)
            ->where('type', 'contrato')
            ->orderBy('id')
            ->pluck('contract_number')
            ->all();

        $this->assertSame(['CTR-00001', 'CTR-00002', 'CTR-00003'], $numbers);
        $this->assertSame(4, (int) $this->tenant->fresh()->next_contract_number);
    }

    public function test_each_tenant_has_its_own_independent_sequence(): void
    {
        $otherTenant = Tenant::factory()->create(['contract_prefix' => 'OTRO']);
        $otherRole = Role::create(['name' => 'Admin 2', 'permissions' => ['*']]);
        $otherStaff = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role_id'   => $otherRole->id,
        ]);
        $otherCustomer = $this->makeCustomer($otherTenant);

        Sanctum::actingAs($this->staff);
        $this->sign($this->customer)->assertJsonPath('document.contract_number', 'CTR-00001');

        Sanctum::actingAs($otherStaff);
        $this->sign($otherCustomer)->assertJsonPath('document.contract_number', 'OTRO-00001');
    }

    public function test_the_number_is_printed_inside_the_generated_pdf(): void
    {
        Sanctum::actingAs($this->staff);

        $fakePdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $fakePdf->shouldReceive('output')->once()->andReturn('%PDF-fake');

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(fn($view, $data) => $view === 'documents.contract_pdf'
                && $data['contractNumber'] === 'CTR-00001')
            ->andReturn($fakePdf);

        $this->sign($this->customer)->assertStatus(201);
    }

    public function test_the_stored_file_is_named_after_the_consecutive(): void
    {
        Sanctum::actingAs($this->staff);

        $this->sign($this->customer)->assertStatus(201);

        $document = CustomerDocument::where('customer_id', $this->customer->id)->firstOrFail();

        $this->assertSame('contrato_CTR-00001.pdf', $document->file_name);
        Storage::disk('s3')->assertExists($document->file_path);
    }

    public function test_contract_data_announces_the_next_number_without_consuming_it(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/customers/{$this->customer->id}/contract-data")
            ->assertStatus(200)
            ->assertJsonPath('next_contract_number', 'CTR-00001');

        // Consultar el preview no puede gastar números de la secuencia.
        $this->assertSame(1, (int) $this->tenant->fresh()->next_contract_number);

        $this->sign($this->customer)->assertJsonPath('document.contract_number', 'CTR-00001');
    }

    public function test_uploaded_documents_never_get_a_contract_number(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/customers/{$this->customer->id}/documents", [
            'type'  => 'contrato',
            'files' => [\Illuminate\Http\UploadedFile::fake()->create('escaneado.pdf', 10, 'application/pdf')],
        ])->assertStatus(201);

        $this->assertNull(CustomerDocument::where('customer_id', $this->customer->id)->firstOrFail()->contract_number);
        $this->assertSame(1, (int) $this->tenant->fresh()->next_contract_number);
    }

    /**
     * El prefijo es libre: el ISP escribe lo que quiera y se respeta tal cual.
     * Sólo se cae al default cuando no queda nada útil.
     */
    public function test_format_respects_whatever_prefix_the_isp_wrote(): void
    {
        $this->assertSame('CTR-00001', ContractNumberService::format(null, 1));
        $this->assertSame('CTR-00001', ContractNumberService::format('', 1));
        $this->assertSame('CTR-00042', ContractNumberService::format('   ', 42));

        // Caracteres que la validación anterior descartaba y ahora sobreviven.
        $this->assertSame('FIBRA X-00005', ContractNumberService::format('FIBRA X', 5));
        $this->assertSame('CÓRDOBA-00009', ContractNumberService::format('CÓRDOBA', 9));
        $this->assertSame('FIBRA_2026-00003', ContractNumberService::format('FIBRA_2026', 3));
        $this->assertSame('CTR.2026-00004', ContractNumberService::format('CTR.2026', 4));

        // Cinco dígitos de relleno, pero el número nunca se trunca.
        $this->assertSame('ABC-99999', ContractNumberService::format('ABC', 99999));
        $this->assertSame('ABC-100000', ContractNumberService::format('ABC', 100000));
    }

    /**
     * El guion sólo aparece cuando el prefijo termina en letra o dígito: quien
     * escribe «CNO/» o «Contrato N° » ya puso su propio separador.
     */
    public function test_format_does_not_add_a_separator_when_the_prefix_already_ends_in_one(): void
    {
        $this->assertSame('CNO/00001', ContractNumberService::format('CNO/', 1));
        $this->assertSame('Contrato N° 00012', ContractNumberService::format('Contrato N° ', 12));
        $this->assertSame('CTR-00007', ContractNumberService::format('CTR-', 7));
        $this->assertSame('FIBRA_00002', ContractNumberService::format('FIBRA_', 2));
        // El espacio a la izquierda es accidental y sí se descarta.
        $this->assertSame('CNO-00003', ContractNumberService::format('   CNO', 3));
    }

    /**
     * El número puede llevar «/», «°» o acentos; la clave de S3 no. El nombre
     * del archivo se deriva saneado, conservando siempre la parte numérica.
     */
    public function test_file_name_is_sanitised_even_when_the_prefix_is_not(): void
    {
        $this->assertSame('contrato_CTR-00001.pdf', ContractNumberService::fileName('CTR-00001'));
        // La barra crearía una carpeta fantasma dentro del bucket.
        $this->assertSame('contrato_CNO-00001.pdf', ContractNumberService::fileName('CNO/00001'));
        $this->assertStringNotContainsString('/', ContractNumberService::fileName('A/B/C/00001'));
        $this->assertSame('contrato_FIBRA-X-00005.pdf', ContractNumberService::fileName('FIBRA X-00005'));
        $this->assertSame('contrato_00099.pdf', ContractNumberService::fileName('・00099'));
        // Un prefijo que no deja nada saneable no puede producir un nombre vacío.
        $this->assertSame('contrato_contrato.pdf', ContractNumberService::fileName('・・・'));
    }

    public function test_a_free_form_prefix_survives_end_to_end_into_the_document(): void
    {
        $this->tenant->update(['contract_prefix' => 'CNO/']);
        Sanctum::actingAs($this->staff);

        $this->sign($this->customer)
            ->assertStatus(201)
            ->assertJsonPath('document.contract_number', 'CNO/00001');

        $document = CustomerDocument::where('customer_id', $this->customer->id)->firstOrFail();

        // El número lleva la barra; la ruta en S3 no puede llevarla.
        $this->assertSame('CNO/00001', $document->contract_number);
        $this->assertSame('contrato_CNO-00001.pdf', $document->file_name);
        $this->assertSame(
            "customer_documents/{$this->customer->id}/contrato_CNO-00001.pdf",
            $document->file_path
        );
        Storage::disk('s3')->assertExists($document->file_path);
    }
}
