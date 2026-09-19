<?php

namespace Tests\Feature\Billing;

use App\Constants\Permissions;
use App\Models\AuditLog;
use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Una factura emitida se ANULA; no se borra.
 *
 * EL AGUJERO QUE ESTO CIERRA
 *
 * `DELETE /billing/invoices/{id}` destruía cualquier factura: emitida, pagada,
 * vencida o siendo el cargo de un ticket. Se llevaba por delante el número
 * consecutivo, los ítems, el titular congelado y el vínculo con el ticket.
 *
 * Y anular ya se podía, pero por la puerta de atrás: un `PUT` con
 * `status: cancelled` detrás de `view_billing` —un permiso de LECTURA— sin
 * motivo, sin confirmación y sin una línea en `audit_logs`. El propio modal de
 * borrado lo recomendaba.
 *
 * Ahora: borrar sólo alcanza un borrador sin estrenar —que el sistema no genera
 * nunca— y anular es una operación con permiso propio, motivo obligatorio y
 * auditoría.
 */
class InvoiceVoidingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $contador;
    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::factory()->create();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se lleva ese id, así que se
        // quema uno: sin esto los casos negativos serían falsos positivos.
        Role::create([
            'name' => 'Superadmin global', 'code' => 'superadmin',
            'permissions' => ['*'], 'tenant_id' => null,
        ]);

        $this->cliente  = $this->clienteDe($this->tenant);
        $this->contador = $this->usuarioCon([
            Permissions::VIEW_BILLING,
            Permissions::DELETE_INVOICE,
            Permissions::INVOICE_VOID,
        ]);
    }

    // ── Andamiaje ────────────────────────────────────────────────────────

    private function clienteDe(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id' => $user->id, 'name' => 'Axel', 'last_name' => 'Cano', 'status' => true,
        ]);

        return $user;
    }

    /** @param array<int, string> $permisos */
    private function usuarioCon(array $permisos, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;

        $rol = Role::create([
            'name' => 'Rol ' . uniqid(), 'code' => 'accounting',
            'permissions' => $permisos, 'tenant_id' => $tenant->id,
        ]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $rol->id]);
    }

    private function factura(array $extra = []): Invoice
    {
        // `$extra` PRIMERO: la unión de arrays de PHP conserva la clave de la
        // izquierda, así que con los valores por defecto delante un
        // `['status' => 'paid']` se descartaría en silencio.
        $invoice = Invoice::create($extra + [
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'number'       => uniqid('INV-'),
            'issue_date'   => now()->subDays(20),
            'due_date'     => now()->subDays(5),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end'   => now()->subMonth()->endOfMonth(),
            'currency'     => 'COP',
            'subtotal'     => 50000, 'tax' => 0, 'total' => 50000, 'balance_due' => 50000,
            'status'       => Invoice::STATUS_ISSUED,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'carried_in'   => 0, 'carried_out' => 0,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'type'        => 'plan',
            'description' => 'Servicio mensual',
            'quantity'    => 1,
            'unit_price'  => $invoice->total,
            'amount'      => $invoice->total,
        ]);

        return $invoice;
    }

    /** Una factura con dinero encima: pago real y asignación. */
    private function facturaPagada(float $abono = 50000): Invoice
    {
        $invoice = $this->factura([
            'status'      => $abono >= 50000 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL,
            'balance_due' => 50000 - $abono,
        ]);

        $pago = Payment::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->cliente->id,
            'amount'       => $abono,
            'payment_date' => now()->subDay(),
            'method'       => 'efectivo',
            'status'       => 'completed',
        ]);

        PaymentAllocation::create([
            'payment_id' => $pago->id,
            'invoice_id' => $invoice->id,
            'amount'     => $abono,
        ]);

        return $invoice->fresh();
    }

    private function ticket(string $estado = 'closed'): SupportTicket
    {
        return SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->cliente->id,
            'subject'   => 'Revisión en sitio',
            'status'    => $estado, 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    private const MOTIVO = 'Cobro duplicado: ya se había facturado en el periodo anterior.';

    private function anular(Invoice $invoice, ?string $motivo = null, ?User $como = null)
    {
        return $this->actingAs($como ?? $this->contador)
            ->postJson("/api/billing/invoices/{$invoice->id}/void", [
                'reason' => $motivo ?? self::MOTIVO,
            ]);
    }

    // ── Permisos ─────────────────────────────────────────────────────────

    #[Test]
    public function sin_invoice_void_no_se_puede_anular(): void
    {
        $invoice = $this->factura();

        // Tiene view_billing y hasta delete_invoice: no basta. Anular es una
        // capacidad aparte, precisamente para que deje de ir con la de editar.
        $sinPermiso = $this->usuarioCon([Permissions::VIEW_BILLING, Permissions::DELETE_INVOICE]);

        $this->anular($invoice, como: $sinPermiso)
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'invoice_void');

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
    }

    #[Test]
    public function el_put_generico_ya_no_puede_anular_una_factura(): void
    {
        $invoice = $this->factura();

        // Éste era el agujero: `view_billing` es de LECTURA y bastaba para
        // sacar una factura de las cuentas.
        $soloLectura = $this->usuarioCon([Permissions::VIEW_BILLING]);

        $this->actingAs($soloLectura)
            ->putJson("/api/billing/invoices/{$invoice->id}", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // `pending` tampoco: nunca fue un estado válido del CHECK y en
        // PostgreSQL reventaba con un 23514.
        $this->actingAs($soloLectura)
            ->putJson("/api/billing/invoices/{$invoice->id}", ['status' => 'pending'])
            ->assertStatus(422);

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
    }

    #[Test]
    public function la_migracion_da_invoice_void_a_quien_ya_podia_borrar(): void
    {
        $conBorrado = Role::create([
            'name' => 'Contabilidad', 'code' => 'accounting',
            'permissions' => [Permissions::VIEW_BILLING, Permissions::DELETE_INVOICE],
            'tenant_id' => $this->tenant->id,
        ]);

        $soloLectura = Role::create([
            'name' => 'Consulta', 'code' => 'staff',
            'permissions' => [Permissions::VIEW_BILLING], 'tenant_id' => $this->tenant->id,
        ]);

        $comodin = Role::create([
            'name' => 'Dueño', 'code' => 'admin',
            'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $migracion = require database_path(
            'migrations/2026_09_19_000002_grant_invoice_void_to_roles_that_could_delete.php'
        );
        $migracion->up();

        $this->assertContains(
            Permissions::INVOICE_VOID,
            Role::withoutGlobalScopes()->find($conBorrado->id)->permissions,
            'Quien podía destruir una factura debe poder anularla: si no, el despliegue lo deja sin salida.',
        );

        $this->assertNotContains(
            Permissions::INVOICE_VOID,
            Role::withoutGlobalScopes()->find($soloLectura->id)->permissions,
            '`view_billing` es de lectura y que bastara para anular era el agujero.',
        );

        $this->assertSame(['*'], Role::withoutGlobalScopes()->find($comodin->id)->permissions);

        // Idempotente y reversible.
        $migracion->up();
        $migracion->down();

        $this->assertNotContains(
            Permissions::INVOICE_VOID,
            Role::withoutGlobalScopes()->find($conBorrado->id)->permissions,
        );
    }

    // ── Motivo obligatorio ───────────────────────────────────────────────

    #[Test]
    public function anular_sin_motivo_o_con_uno_demasiado_corto_da_422(): void
    {
        foreach ([null, '', 'corto', str_repeat('a', 501)] as $motivo) {
            $invoice = $this->factura();

            $this->actingAs($this->contador)
                ->postJson(
                    "/api/billing/invoices/{$invoice->id}/void",
                    $motivo === null ? [] : ['reason' => $motivo],
                )
                ->assertStatus(422)
                ->assertJsonValidationErrors('reason');

            $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        }
    }

    // ── Anulación por estado ─────────────────────────────────────────────

    #[Test]
    public function una_factura_emitida_o_vencida_se_anula_y_conserva_todo(): void
    {
        foreach ([Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE] as $estado) {
            $invoice = $this->factura(['status' => $estado]);
            $numero  = $invoice->number;
            $total   = (float) $invoice->total;

            $this->anular($invoice)->assertOk()->assertJsonPath('previous_status', $estado);

            $anulada = $invoice->fresh();

            $this->assertSame(Invoice::STATUS_VOID, $anulada->status);
            // Sale de la cobranza: es el saldo lo que miran recordatorios,
            // cortes y mora.
            $this->assertSame(0.0, (float) $anulada->balance_due);

            // Y NO se pierde nada de lo que la identifica.
            $this->assertSame($numero, $anulada->number);
            $this->assertSame($total, (float) $anulada->total);
            $this->assertSame($this->cliente->id, (int) $anulada->customer_id);
            $this->assertNotNull($anulada->issue_date);
            $this->assertSame(1, InvoiceItem::where('invoice_id', $anulada->id)->count());

            $this->assertSame(self::MOTIVO, $anulada->void_reason);
            $this->assertSame($this->contador->id, (int) $anulada->voided_by);
            $this->assertNotNull($anulada->voided_at);
        }
    }

    #[Test]
    public function anular_una_pagada_devuelve_el_dinero_como_saldo_y_conserva_el_pago(): void
    {
        $invoice = $this->facturaPagada(50000);
        $pagoId  = PaymentAllocation::where('invoice_id', $invoice->id)->value('payment_id');

        $this->anular($invoice)->assertOk();

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);

        // El recaudo OCURRIÓ: entró plata en la caja ese día. Anular una factura
        // no puede reescribir el histórico de tesorería. Es la diferencia con
        // `markInvoiceUnpaid()`, que sí borra el pago.
        $this->assertDatabaseHas('payments', ['id' => $pagoId]);

        // Lo que sí se suelta es la asignación: el dinero deja de respaldar esta
        // factura y vuelve como saldo a favor del cliente.
        $this->assertSame(0, PaymentAllocation::where('invoice_id', $invoice->id)->count());
        // La columna es `from_payment_id`: el saldo queda atado al pago que lo
        // trajo, para que una anulación posterior de ese pago lo encuentre.
        $credito = CustomerCredit::withoutGlobalScopes()
            ->where('from_payment_id', $pagoId)
            ->where('type', CustomerCredit::TYPE_EARNED)
            ->first();

        $this->assertNotNull($credito, 'El dinero aplicado debe volver como saldo a favor, no evaporarse.');
        $this->assertSame(50000.0, (float) $credito->amount);
        $this->assertStringContainsString('anulada', (string) $credito->reason);
    }

    #[Test]
    public function una_factura_abonada_parcialmente_tambien_se_anula(): void
    {
        $invoice = $this->facturaPagada(20000);

        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        $this->anular($invoice)->assertOk();

        $anulada = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_VOID, $anulada->status);
        $this->assertSame(0.0, (float) $anulada->balance_due);
        $this->assertSame(50000.0, (float) $anulada->total, 'El importe facturado no se reescribe.');
    }

    #[Test]
    public function una_factura_ya_anulada_no_se_vuelve_a_anular(): void
    {
        foreach ([Invoice::STATUS_VOID, Invoice::STATUS_CANCELLED] as $estado) {
            $invoice = $this->factura(['status' => $estado]);

            $this->anular($invoice)
                ->assertStatus(422)
                ->assertJsonPath('error', 'invoice_already_void');
        }
    }

    #[Test]
    public function una_factura_anulada_es_de_solo_lectura(): void
    {
        $invoice = $this->factura();
        $this->anular($invoice)->assertOk();

        // Editar, añadir ítems y marcar como no pagada: las tres rechazadas.
        $this->actingAs($this->contador)
            ->putJson("/api/billing/invoices/{$invoice->id}", ['total' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_is_void');

        $this->actingAs($this->contador)
            ->postJson("/api/billing/invoices/{$invoice->id}/items", [
                'description' => 'Ajuste', 'amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_is_void');

        $this->actingAs($this->contador)
            ->postJson("/api/billing/invoices/{$invoice->id}/mark-unpaid")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_is_void');

        $this->assertSame(50000.0, (float) $invoice->fresh()->total);
    }

    // ── El borrado físico queda bloqueado ────────────────────────────────

    #[Test]
    public function no_se_puede_borrar_una_factura_emitida_pagada_parcial_ni_vencida(): void
    {
        $estados = [
            Invoice::STATUS_ISSUED,
            Invoice::STATUS_PAID,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ];

        foreach ($estados as $estado) {
            $invoice = $this->factura(['status' => $estado]);

            $this->actingAs($this->contador)
                ->deleteJson("/api/billing/invoices/{$invoice->id}")
                ->assertStatus(422)
                ->assertJsonPath('error', 'invoice_deletion_blocked');

            $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $estado]);
        }
    }

    #[Test]
    public function tampoco_se_puede_borrar_una_ya_anulada(): void
    {
        $invoice = $this->factura(['status' => Invoice::STATUS_VOID]);

        $this->actingAs($this->contador)
            ->deleteJson("/api/billing/invoices/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_deletion_blocked');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    #[Test]
    public function el_bloqueo_esta_en_el_servidor_y_no_solo_en_el_boton(): void
    {
        $invoice = $this->factura();

        // El usuario TIENE `delete_invoice`: no es un 403 por permisos, es que
        // la operación no procede. Ocultar el botón no habría bastado.
        $respuesta = $this->actingAs($this->contador)
            ->deleteJson("/api/billing/invoices/{$invoice->id}");

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('Anúlala', $respuesta->json('message'));
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    #[Test]
    public function un_borrador_sin_numero_y_sin_ticket_si_se_puede_borrar(): void
    {
        // La política real del producto: NINGUNA factura llega a este estado.
        // Toda ruta de creación llama a `generateInvoiceNumber()` y deja el
        // estado en `issued`. `draft` es sólo el valor por defecto de la
        // columna, así que esto cubre una fila insertada a mano.
        $borrador = $this->factura(['status' => Invoice::STATUS_DRAFT, 'number' => null]);

        $this->actingAs($this->contador)
            ->deleteJson("/api/billing/invoices/{$borrador->id}")
            ->assertOk();

        $this->assertDatabaseMissing('invoices', ['id' => $borrador->id]);
    }

    #[Test]
    public function un_borrador_que_ya_tiene_numero_no_se_borra(): void
    {
        $borrador = $this->factura(['status' => Invoice::STATUS_DRAFT]);

        $this->actingAs($this->contador)
            ->deleteJson("/api/billing/invoices/{$borrador->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_deletion_blocked');
    }

    // ── Facturas ligadas a un ticket ─────────────────────────────────────

    #[Test]
    public function una_factura_de_ticket_no_se_borra_aunque_sea_borrador(): void
    {
        $ticket = $this->ticket();

        $invoice = $this->factura([
            'status' => Invoice::STATUS_DRAFT, 'number' => null, 'ticket_id' => $ticket->id,
            'invoice_type' => Invoice::TYPE_SERVICE_CHARGE,
        ]);

        $respuesta = $this->actingAs($this->contador)
            ->deleteJson("/api/billing/invoices/{$invoice->id}");

        $respuesta->assertStatus(422)->assertJsonPath('ticket_id', $ticket->id);
        $this->assertStringContainsString("ticket #{$ticket->id}", $respuesta->json('message'));
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    #[Test]
    public function anular_una_factura_de_ticket_conserva_el_vinculo(): void
    {
        $ticket  = $this->ticket();
        $invoice = $this->factura([
            'ticket_id' => $ticket->id, 'invoice_type' => Invoice::TYPE_SERVICE_CHARGE,
        ]);
        $numero = $invoice->number;

        $this->anular($invoice)->assertOk();

        $anulada = $invoice->fresh();

        $this->assertSame(Invoice::STATUS_VOID, $anulada->status);
        $this->assertSame($ticket->id, (int) $anulada->ticket_id, 'El cargo sigue atado a su ticket.');
        $this->assertSame($numero, $anulada->number, 'El consecutivo no se pierde.');
    }

    // ── La regla del PR C, de punta a punta ──────────────────────────────

    #[Test]
    public function la_anulacion_desbloquea_el_archivado_del_ticket(): void
    {
        $ticket  = $this->ticket();
        $invoice = $this->factura([
            'ticket_id' => $ticket->id, 'invoice_type' => Invoice::TYPE_SERVICE_CHARGE,
        ]);

        $operador = $this->usuarioCon([
            Permissions::VIEW_BILLING, Permissions::INVOICE_VOID,
            Permissions::TICKET_VIEW, Permissions::TICKET_ARCHIVE,
        ]);

        $cuerpo = [
            'reason'            => 'El ticket se abrió por duplicado y el cargo no procede.',
            'confirm_ticket_id' => (string) $ticket->id,
        ];

        // Con el cargo vivo, el PR C lo impide. Éste es EXACTAMENTE el mensaje
        // que en producción salía detrás del modal.
        $this->actingAs($operador)
            ->postJson("/api/support/{$ticket->id}/archive", $cuerpo)
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticket_has_active_charge');

        $this->anular($invoice, como: $operador)->assertOk();

        // Anulada la factura, el ticket ya se archiva.
        $this->actingAs($operador)
            ->postJson("/api/support/{$ticket->id}/archive", $cuerpo)
            ->assertOk();

        $this->assertNotNull(SupportTicket::withTrashed()->find($ticket->id)->deleted_at);

        // Y el cargo anulado sigue ahí, con su número y su vínculo.
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id, 'ticket_id' => $ticket->id, 'status' => Invoice::STATUS_VOID,
        ]);
    }

    // ── Auditoría ────────────────────────────────────────────────────────

    #[Test]
    public function la_anulacion_deja_un_evento_completo_en_audit_logs(): void
    {
        $ticket  = $this->ticket();
        $invoice = $this->factura([
            'ticket_id' => $ticket->id, 'invoice_type' => Invoice::TYPE_SERVICE_CHARGE,
        ]);

        $respuesta = $this->anular($invoice)->assertOk();

        $log = AuditLog::withoutGlobalScopes()
            ->where('action', 'invoice.voided')
            ->where('model_id', $invoice->id)
            ->firstOrFail();

        // Actor.
        $this->assertSame($this->contador->id, (int) $log->user_id);

        // Factura y ticket.
        $this->assertSame(Invoice::class, $log->model_type);
        $this->assertSame($invoice->number, $log->old_values['number']);
        $this->assertSame($ticket->id, (int) $log->old_values['ticket_id']);

        // Estado anterior y nuevo.
        $this->assertSame(Invoice::STATUS_ISSUED, $log->old_values['status']);
        $this->assertSame(Invoice::STATUS_VOID, $log->new_values['status']);

        // Motivo, fecha y correlación.
        $this->assertSame(self::MOTIVO, $log->new_values['reason']);
        $this->assertNotNull($log->created_at);
        $this->assertSame(
            $respuesta->json('correlation_id'),
            $log->new_values['correlation_id'],
            'La respuesta debe devolver la misma correlación que quedó registrada.',
        );

        $this->assertSame((int) $this->tenant->id, (int) $log->tenant_id);
    }

    #[Test]
    public function un_intento_rechazado_no_deja_evento_de_anulacion(): void
    {
        $invoice = $this->factura();

        $this->anular($invoice, 'corto')->assertStatus(422);

        $this->assertSame(
            0,
            AuditLog::withoutGlobalScopes()->where('action', 'invoice.voided')->count(),
            'La validación corre antes de auditar: un motivo inválido no es un intento de anular.',
        );
    }

    // ── Aislamiento por tenant ───────────────────────────────────────────

    #[Test]
    public function un_isp_no_puede_anular_la_factura_de_otro(): void
    {
        $otro = Tenant::factory()->create();
        $suCliente = $this->clienteDe($otro);

        $ajena = Invoice::create([
            'tenant_id'    => $otro->id,
            'customer_id'  => $suCliente->id,
            'number'       => uniqid('INV-'),
            'issue_date'   => now(), 'due_date' => now()->addDays(5),
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'currency'     => 'COP',
            'subtotal'     => 10000, 'tax' => 0, 'total' => 10000, 'balance_due' => 10000,
            'status'       => Invoice::STATUS_ISSUED, 'invoice_type' => Invoice::TYPE_MONTHLY,
            'carried_in'   => 0, 'carried_out' => 0,
        ]);

        $this->anular($ajena)->assertNotFound();

        $this->assertSame(Invoice::STATUS_ISSUED, $ajena->fresh()->status);
    }

    // ── Efectos colaterales que NO deben ocurrir ─────────────────────────

    #[Test]
    public function anular_una_mensual_no_deja_lapida_de_regeneracion(): void
    {
        $invoice = $this->factura();

        $this->anular($invoice)->assertOk();

        // Borrar sí dejaba una lápida `suppressed` en `billing_action_logs`,
        // porque la fila desaparecía y el periodo quedaba libre. Anular no la
        // necesita: `monthlyInvoiceExists()` no filtra por estado, así que la
        // factura anulada sigue ocupando su periodo.
        $this->assertSame(
            0,
            DB::table('billing_action_logs')->where('status', 'suppressed')->count(),
        );
    }

    #[Test]
    public function la_factura_anulada_desaparece_de_los_totales_pero_no_del_listado(): void
    {
        $viva    = $this->factura();
        $anulada = $this->factura();

        $this->anular($anulada)->assertOk();

        $listado = $this->actingAs($this->contador)
            ->getJson('/api/billing/invoices')
            ->assertOk()
            ->json();

        $ids = array_column($listado['data'] ?? $listado['invoices']['data'] ?? [], 'id');

        // Sigue siendo consultable: es un registro contable, no un borrado.
        $this->assertContains($anulada->id, $ids);
        $this->assertContains($viva->id, $ids);
    }
}
