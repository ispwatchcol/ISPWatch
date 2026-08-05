<?php

namespace Tests\Feature\Exports;

use App\Models\CustomerProfile;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exportación a CSV de los tres listados de Finanzas.
 *
 * La promesa del botón es "exportar lo que estoy viendo, completo": el archivo
 * cubre TODO el filtro aplicado, no la página visible. Por eso los exports
 * comparten con los listados el mismo constructor de consulta — si cada uno
 * armara sus filtros por su cuenta, acabarían divergiendo y el CSV dejaría de
 * corresponder a la pantalla sin que nada lo delatara.
 */
class FinanceCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'role_id'       => $role->id,
            'user_name'     => 'Laura',
            'user_lastname' => 'Cajera',
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'status'    => true,
        ]);
    }

    /** Deshace el formato del CSV para poder afirmar sobre filas y columnas. */
    private function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines   = array_values(array_filter(explode("\n", trim($content)), fn ($l) => trim($l) !== ''));

        return array_map(fn ($line) => str_getcsv(rtrim($line, "\r"), ';', '"', ''), $lines);
    }

    private function invoice(array $attributes = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'customer_id'  => $this->customer->id,
            'tenant_id'    => $this->tenant->id,
            'number'       => 'INV-EXP-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 10000,
            'balance_due'  => 10000,
            'status'       => 'issued',
        ], $attributes));
    }

    private function payment(array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'amount'       => 1000,
            'payment_date' => '2026-07-15',
            'method'       => 'cash',
            'status'       => 'completed',
        ], $attributes));
    }

    private function expense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'expense_date' => '2026-07-15',
            'amount'       => 1000,
            'status'       => Expense::STATUS_ACTIVE,
        ], $attributes));
    }

    // ── Facturación ───────────────────────────────────────────────────────────

    #[Test]
    public function el_csv_de_facturas_trae_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        // El listado pagina de 20 en 20; el CSV debe traer las 25.
        foreach (range(1, 25) as $i) {
            $this->invoice();
        }

        $response = $this->get('/api/billing/invoices/export')->assertStatus(200);
        $rows     = $this->parseCsv($response->streamedContent());

        $this->assertCount(26, $rows, '25 facturas + la fila de cabeceras.');
        $this->assertSame('Número', $rows[0][0]);
    }

    #[Test]
    public function el_csv_de_facturas_respeta_los_filtros_de_la_pantalla(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['status' => 'overdue', 'total' => 90000, 'balance_due' => 90000]);
        $this->invoice(['status' => 'paid',    'total' => 10000, 'balance_due' => 0]);

        $rows = $this->parseCsv(
            $this->get('/api/billing/invoices/export?status=overdue')->assertStatus(200)->streamedContent()
        );

        $this->assertCount(2, $rows, 'Sólo la vencida + cabeceras.');
        $this->assertSame('overdue', $rows[1][4]);
        // Importes con coma decimal: en un Excel es-CO "90000.00" se lee como texto.
        $this->assertSame('90000,00', $rows[1][8]);
    }

    #[Test]
    public function el_csv_de_facturas_no_incluye_las_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice();

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        Invoice::create([
            'customer_id'  => $ajeno->id,
            'tenant_id'    => $otroTenant->id,
            'number'       => 'INV-AJENA-9999',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 999000,
            'balance_due'  => 999000,
            'status'       => 'issued',
        ]);

        $contenido = $this->get('/api/billing/invoices/export')->assertStatus(200)->streamedContent();

        $this->assertCount(2, $this->parseCsv($contenido));
        $this->assertStringNotContainsString('INV-AJENA-9999', $contenido);
    }

    // ── Recaudos ──────────────────────────────────────────────────────────────

    #[Test]
    public function el_csv_de_recaudos_trae_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (range(1, 25) as $i) {
            $this->payment(['reference' => "REF-$i"]);
        }

        // per_page sólo afecta al listado: el CSV lo ignora por diseño.
        $rows = $this->parseCsv(
            $this->get('/api/billing/payments/export?per_page=10')->assertStatus(200)->streamedContent()
        );

        $this->assertCount(26, $rows, '25 recaudos + cabeceras.');
    }

    #[Test]
    public function el_csv_de_recaudos_respeta_el_rango_de_fechas_y_marca_el_saldo_a_favor(): void
    {
        Sanctum::actingAs($this->staff);

        $this->payment(['payment_date' => '2026-07-10', 'amount' => 1000, 'created_by' => $this->staff->id]);
        $this->payment(['payment_date' => '2026-06-10', 'amount' => 999000]);

        $rows = $this->parseCsv(
            $this->get('/api/billing/payments/export?date_from=2026-07-01&date_to=2026-07-31')
                ->assertStatus(200)->streamedContent()
        );

        $this->assertCount(2, $rows);
        $this->assertSame('2026-07-10', $rows[1][0]);
        $this->assertSame('Juan Pérez', $rows[1][1]);
        $this->assertSame('Laura Cajera', $rows[1][5]);
        // Sin asignaciones a facturas, el recaudo quedó como saldo a favor.
        $this->assertSame('Saldo a favor', $rows[1][6]);
    }

    #[Test]
    public function el_csv_de_recaudos_marca_como_sistema_los_pagos_automaticos(): void
    {
        Sanctum::actingAs($this->staff);

        $this->payment(['created_by' => null]);

        $rows = $this->parseCsv(
            $this->get('/api/billing/payments/export')->assertStatus(200)->streamedContent()
        );

        $this->assertSame('sistema', $rows[1][5]);
    }

    // ── Gastos ────────────────────────────────────────────────────────────────

    #[Test]
    public function el_csv_de_gastos_trae_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (range(1, 25) as $i) {
            $this->expense(['description' => "Gasto $i"]);
        }

        $rows = $this->parseCsv(
            $this->get('/api/expenses/export?per_page=10')->assertStatus(200)->streamedContent()
        );

        $this->assertCount(26, $rows, '25 gastos + cabeceras.');
    }

    #[Test]
    public function el_csv_de_gastos_respeta_la_busqueda_y_muestra_sin_categoria(): void
    {
        Sanctum::actingAs($this->staff);

        $arriendo = ExpenseCategory::create(['name' => 'Arriendo']);

        $this->expense(['description' => 'Arriendo de la oficina', 'expense_category_id' => $arriendo->id]);
        $this->expense(['description' => 'Pago energía']); // sin categoría
        $this->expense(['description' => 'Combustible']);

        $rows = $this->parseCsv(
            $this->get('/api/expenses/export?search=' . urlencode('energía'))
                ->assertStatus(200)->streamedContent()
        );

        $this->assertCount(2, $rows);
        $this->assertSame('Sin categoría', $rows[1][1]);
        $this->assertSame('Pago energía', $rows[1][2]);
    }

    #[Test]
    public function el_csv_de_gastos_incluye_los_anulados_con_su_estado(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['description' => 'Vigente']);
        $this->expense(['description' => 'Anulado', 'status' => Expense::STATUS_VOID]);

        $rows = $this->parseCsv(
            $this->get('/api/expenses/export')->assertStatus(200)->streamedContent()
        );

        // El archivo es el registro completo: esconder los anulados ocultaría
        // justamente las correcciones. El estado va en su columna.
        $this->assertCount(3, $rows);
        $estados = [$rows[1][5], $rows[2][5]];
        sort($estados);
        $this->assertSame(['activo', 'anulado'], $estados);
    }

    // ── Formato y acceso ──────────────────────────────────────────────────────

    #[Test]
    public function el_csv_sale_con_bom_y_separador_de_punto_y_coma_para_excel(): void
    {
        Sanctum::actingAs($this->staff);
        $this->expense(['description' => 'Con tildes: ñáéí']);

        $response  = $this->get('/api/expenses/export')->assertStatus(200);
        $contenido = $response->streamedContent();

        // Sin BOM, Excel abre el archivo en ANSI y las tildes salen como "Ã±".
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        // Con separador de coma, un Excel es-CO apelmaza todo en una columna.
        $this->assertStringContainsString(';', $contenido);
        $this->assertStringContainsString('ñáéí', $contenido);

        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    #[Test]
    public function la_exportacion_exige_estar_autenticado(): void
    {
        $this->getJson('/api/billing/invoices/export')->assertStatus(401);
        $this->getJson('/api/billing/payments/export')->assertStatus(401);
        $this->getJson('/api/expenses/export')->assertStatus(401);
    }
}
