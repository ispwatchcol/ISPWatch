<?php

namespace Tests\Feature\Documents;

use App\Models\CustomerDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-9 / KAN-62: documents:audit-storage separa los documentos con archivo, los
 * que lo perdieron y los que no se pudieron consultar, y no toca ninguna fila.
 */
class AuditCustomerDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->tenant   = Tenant::factory()->create();
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function document(string $path, string $createdAt, ?Tenant $tenant = null, bool $withFile = true): CustomerDocument
    {
        $tenant ??= $this->tenant;
        if ($withFile) {
            Storage::disk('s3')->put($path, 'bytes');
        }

        $document = CustomerDocument::withoutGlobalScopes()->create([
            'tenant_id'   => $tenant->id,
            'customer_id' => $this->customer->id,
            'type'        => 'cedula',
            'file_name'   => basename($path),
            'file_path'   => $path,
            'file_size'   => 5,
            'mime_type'   => 'application/pdf',
        ]);
        DB::table('customer_documents')->where('id', $document->id)->update(['created_at' => $createdAt]);

        return $document;
    }

    #[Test]
    public function cuenta_encontrados_y_perdidos_y_distingue_los_anteriores_a_s3(): void
    {
        $this->document('customer_documents/1/bueno.pdf', '2026-09-01');
        $this->document('customer_documents/1/viejo.pdf', '2026-07-10', withFile: false);
        $this->document('customer_documents/1/raro.pdf', '2026-09-15', withFile: false);

        $this->artisan('documents:audit-storage')
            ->expectsTable(['Métrica', 'Cantidad'], [
                ['Documentos revisados', 3],
                ['Con archivo en S3', 1],
                ['Sin archivo', 2],
                ['  de ellos, anteriores al 2026-07-29', 1],
                ['No se pudo consultar (error)', 0],
            ])
            ->expectsOutputToContain('DESPUÉS del paso a S3')
            ->assertExitCode(0);
    }

    #[Test]
    public function no_modifica_ni_borra_ninguna_fila(): void
    {
        $doc = $this->document('customer_documents/1/viejo.pdf', '2026-07-10', withFile: false);
        $antes = DB::table('customer_documents')->where('id', $doc->id)->first();

        $this->artisan('documents:audit-storage')->assertExitCode(0);

        $this->assertEquals($antes, DB::table('customer_documents')->where('id', $doc->id)->first());
    }

    #[Test]
    public function filtra_por_tenant(): void
    {
        $otro = Tenant::factory()->create();
        $this->document('customer_documents/1/propio.pdf', '2026-07-10', withFile: false);
        $this->document('customer_documents/2/ajeno.pdf', '2026-07-10', $otro, withFile: false);

        $this->artisan('documents:audit-storage', ['--tenant' => $this->tenant->id])
            ->expectsTable(['Métrica', 'Cantidad'], [
                ['Documentos revisados', 1],
                ['Con archivo en S3', 0],
                ['Sin archivo', 1],
                ['  de ellos, anteriores al 2026-07-29', 1],
                ['No se pudo consultar (error)', 0],
            ])
            ->assertExitCode(0);
    }

    #[Test]
    public function un_error_del_almacenamiento_no_se_cuenta_como_perdido(): void
    {
        $this->document('customer_documents/1/x.pdf', '2026-09-01');

        $roto = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $roto->shouldReceive('exists')->andThrow(new \RuntimeException('S3 caído'));
        Storage::set('s3', $roto);

        $this->artisan('documents:audit-storage')
            ->expectsTable(['Métrica', 'Cantidad'], [
                ['Documentos revisados', 1],
                ['Con archivo en S3', 0],
                ['Sin archivo', 0],
                ['  de ellos, anteriores al 2026-07-29', 0],
                ['No se pudo consultar (error)', 1],
            ])
            ->expectsOutputToContain('NO es concluyente')
            ->assertExitCode(0);
    }
}
