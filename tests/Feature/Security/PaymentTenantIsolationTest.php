<?php

namespace Tests\Feature\Security;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aislamiento por tenant de la tabla de recaudos.
 *
 * Payment era el único modelo de dinero sin el global scope de BelongsToTenant,
 * y no era un detalle teórico: `filteredPaymentsQuery()` arranca en
 * `Payment::query()` sin filtro y alimenta a la vez el listado de recaudos y su
 * exportación a CSV, así que cualquier operador con permiso de facturación veía
 * —y podía descargar— los pagos de todos los ISP del sistema. Por el mismo
 * hueco, `Payment::findOrFail($id)` en updatePayment/deletePayment aceptaba el
 * id de otro tenant: no era sólo leer plata ajena, era poder borrarla.
 *
 * Estos tests fijan las dos mitades (lectura y escritura) porque la regresión
 * es invisible: quitar el trait no rompe absolutamente nada que se note.
 */
class PaymentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $operadorA;
    private Payment $pagoDeA;
    private Payment $pagoDeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        $this->operadorA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $clienteB        = User::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->pagoDeA = $this->pago($this->tenantA->id, $this->operadorA->id, 50000);
        $this->pagoDeB = $this->pago($this->tenantB->id, $clienteB->id, 90000);
    }

    private function pago(int $tenantId, int $customerId, float $monto): Payment
    {
        // Sin sesión activa todavía: el tenant va explícito para que el hook de
        // creación del trait no sea lo que se está midiendo.
        return Payment::withoutGlobalScope('tenant')->create([
            'tenant_id'    => $tenantId,
            'customer_id'  => $customerId,
            'amount'       => $monto,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);
    }

    public function test_el_listado_de_recaudos_solo_ve_los_del_tenant_de_la_sesion(): void
    {
        $this->actingAs($this->operadorA);

        $ids = Payment::query()->pluck('id')->all();

        $this->assertSame(
            [$this->pagoDeA->id],
            $ids,
            'El listado de recaudos devolvió pagos de otro tenant.'
        );
    }

    public function test_no_se_puede_leer_un_recaudo_de_otro_tenant_por_id(): void
    {
        $this->actingAs($this->operadorA);

        $this->expectException(ModelNotFoundException::class);

        // 404 y no 403 a propósito: un id ajeno tiene que ser indistinguible de
        // uno que no existe, o el error mismo confirma que el pago existe.
        Payment::findOrFail($this->pagoDeB->id);
    }

    public function test_no_se_puede_borrar_un_recaudo_de_otro_tenant(): void
    {
        $this->actingAs($this->operadorA);

        $borrados = Payment::whereKey($this->pagoDeB->id)->delete();

        $this->assertSame(0, $borrados, 'Se borró un recaudo de otro tenant.');
        $this->assertDatabaseHas('payments', ['id' => $this->pagoDeB->id]);
    }

    public function test_no_se_puede_modificar_un_recaudo_de_otro_tenant(): void
    {
        $this->actingAs($this->operadorA);

        Payment::whereKey($this->pagoDeB->id)->update(['amount' => 1]);

        $this->assertSame(
            '90000.00',
            (string) Payment::withoutGlobalScope('tenant')->find($this->pagoDeB->id)->amount,
            'Se modificó el monto de un recaudo de otro tenant.'
        );
    }

    public function test_un_recaudo_nuevo_hereda_el_tenant_de_la_sesion(): void
    {
        $this->actingAs($this->operadorA);

        $nuevo = Payment::create([
            'customer_id'  => $this->operadorA->id,
            'amount'       => 1000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);

        $this->assertSame($this->tenantA->id, $nuevo->tenant_id);
    }

    public function test_sin_sesion_el_scope_no_filtra_para_no_romper_los_jobs(): void
    {
        // El cobro mensual, los cortes y los recordatorios corren por consola,
        // sin usuario autenticado, y tienen que ver a todos los tenants. Si
        // alguien "endurece" el trait para filtrar siempre, esto lo caza.
        $this->assertCount(2, Payment::query()->get());
    }
}
