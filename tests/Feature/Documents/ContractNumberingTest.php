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
     * El prefijo termina dentro del nombre del archivo y de la ruta en S3, así
     * que cualquier cosa que no sea alfanumérica o guion se descarta antes de
     * armarlo.
     */
    public function test_format_sanitises_the_prefix_and_falls_back_to_the_default(): void
    {
        $this->assertSame('CTR-00001', ContractNumberService::format(null, 1));
        $this->assertSame('CTR-00001', ContractNumberService::format('', 1));
        $this->assertSame('CTR-00042', ContractNumberService::format('   ', 42));
        $this->assertSame('CTR-00007', ContractNumberService::format('../..', 7));
        $this->assertSame('CTR-00008', ContractNumberService::format('---', 8));
        $this->assertSame('FIBRAX-00005', ContractNumberService::format('FIBRA X', 5));
        $this->assertSame('FIBRA-X-00013', ContractNumberService::format('FIBRA-X', 13));
        $this->assertSame('ABC-99999', ContractNumberService::format('ABC', 99999));
        // Pasado el tope de cinco dígitos el número sigue creciendo, no se trunca.
        $this->assertSame('ABC-100000', ContractNumberService::format('ABC', 100000));
    }
}
