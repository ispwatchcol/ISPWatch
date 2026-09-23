<?php

namespace Tests\Feature\Billing;

use App\Constants\Permissions;
use App\Mail\InvoiceCreatedMail;
use App\Mail\PaymentReminderMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `customer_profile.notify_invoice = false` — «No enviar notificaciones de factura».
 *
 * Es una preferencia de CANAL, no de facturación: la factura se sigue generando,
 * se puede consultar y pagar, y la mora/corte funcionan igual. Lo único que se
 * apaga es el aviso de factura nueva y el recordatorio de pago por correo y
 * WhatsApp. Ésa es exactamente la promesa que hace el manual de usuario, y esta
 * suite la fija camino por camino.
 *
 * Hay CUATRO caminos que pueden sacar un mensaje de facturación, y hasta ahora
 * ninguna prueba cubría la preferencia en ninguno de ellos:
 *
 *   1. BillingService::notifyInvoiceCreated()      — automático, al facturar
 *   2. PaymentReminderService::sendDueReminders()  — automático, por scheduler
 *   3. PaymentReminderController::sendReminder()   — manual, UNA factura
 *   4. PaymentReminderController::sendBulkReminders() — manual, EN MASA
 *
 * El 3 es una excepción deliberada y documentada (un humano decide sobre esa
 * factura concreta, en ese momento). El 4 no: hereda la excepción sólo porque
 * está implementado llamando al 3 en un bucle.
 */
class NotifyInvoicePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`; con
        // RefreshDatabase el primer rol creado se lleva ese id. Se quema uno.
        Role::create([
            'name'        => 'Superadmin global',
            'code'        => 'superadmin',
            'permissions' => ['*'],
            'tenant_id'   => null,
        ]);

        // El alta de cliente asigna el rol «Cliente» (o el id 3 por defecto);
        // sin la fila, el INSERT en users choca contra la FK de role_id.
        Role::create([
            'name'        => 'Cliente',
            'code'        => 'cliente',
            'permissions' => [],
            'tenant_id'   => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Andamiaje ────────────────────────────────────────────────────

    /**
     * Cliente con router, config de facturación y una factura pendiente.
     *
     * @return array{tenant: Tenant, router: Router, customer: User, invoice: Invoice, profile: CustomerProfile}
     */
    private function scenario(bool $notifyInvoice, array $profileOverrides = [], ?Tenant $tenant = null): array
    {
        $this->seq++;
        // Por defecto cada escenario es una sede aparte; pasar $tenant permite
        // montar DOS clientes en la MISMA sede, que es lo que hace falta para
        // probar un lote mixto (las facturas llevan scope de tenant, así que un
        // lote que cruzara sedes no representaría nada real).
        $tenant ??= Tenant::factory()->create();

        $config = Billing::create([
            'payment_reminder'  => Carbon::create(2026, 1, 5)->toDateString(),
            'notification_type' => 'email',
            'status'            => 'pending',
        ]);

        $router = Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);

        $customer = User::factory()->create(['tenant_id' => $tenant->id]);

        $profile = CustomerProfile::create(array_merge([
            'user_id'        => $customer->id,
            'tenant_id'      => $tenant->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'status'         => true,
            'notify_invoice' => $notifyInvoice,
        ], $profileOverrides));

        $invoice = Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => "INV-{$this->seq}",
            'issue_date'   => Carbon::create(2026, 6, 1),
            'due_date'     => Carbon::create(2026, 6, 10),
            'period_start' => Carbon::create(2026, 6, 1),
            'period_end'   => Carbon::create(2026, 6, 30),
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);

        return compact('tenant', 'router', 'customer', 'invoice', 'profile');
    }

    /** @param string|array<int,string> $permissions */
    private function userWith(Tenant $tenant, string|array $permissions): User
    {
        $role = Role::create([
            'name'        => 'Rol ' . uniqid(),
            'tenant_id'   => $tenant->id,
            'permissions' => (array) $permissions,
        ]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
    }

    /**
     * El perfil recién creado, localizado por el correo del alta.
     *
     * Por `user_id` y NUNCA por `latest('id')`: `customer_profile` no tiene
     * columna `id` — su clave primaria es `user_id`. PostgreSQL rechaza el
     * `order by "id"` de plano; SQLite lo ACEPTA, porque un identificador
     * entrecomillado que no resuelve a ninguna columna lo trata como literal de
     * texto (comprobado: `order by "columna_inventada"` también pasa). Es decir
     * que en SQLite ese orden no ordenaba nada y `first()` devolvía una fila
     * cualquiera; el test pasaba sólo porque había una sola candidata.
     */
    private function profileByEmail(string $email): CustomerProfile
    {
        $user = User::where('email', $email)->firstOrFail();

        return CustomerProfile::where('user_id', $user->id)->firstOrFail();
    }

    // ── 1. Persistencia: guardar, releer, actualización parcial ──────

    #[Test]
    public function a_new_customer_defaults_to_notifications_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($this->userWith($tenant, [Permissions::ADD_CLIENTS, Permissions::VIEW_CLIENTS]));

        $email = uniqid() . '@example.test';

        $this->postJson('/api/customers', [
            'user_name' => 'cliente' . uniqid(),
            'email'     => $email,
            'password'  => 'Secreta123',
            'cedula'    => (string) random_int(100000000, 999999999),
            'name'      => 'Ana',
            'last_name' => 'Gómez',
        ])->assertCreated();

        $profile = $this->profileByEmail($email);
        $this->assertTrue((bool) $profile->notify_invoice, 'Un alta nueva nace con el aviso ENCENDIDO.');
    }

    #[Test]
    public function the_preference_can_be_saved_at_creation_and_read_back(): void
    {
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($this->userWith($tenant, [Permissions::ADD_CLIENTS, Permissions::VIEW_CLIENTS]));

        $email = uniqid() . '@example.test';

        $this->postJson('/api/customers', [
            'user_name'      => 'cliente' . uniqid(),
            'email'          => $email,
            'password'       => 'Secreta123',
            'cedula'         => (string) random_int(100000000, 999999999),
            'name'           => 'Silenciado',
            'last_name'      => 'Perez',
            'notify_invoice' => false,
        ])->assertCreated();

        $profile = $this->profileByEmail($email);
        $this->assertFalse((bool) $profile->notify_invoice);

        // Y vuelve a la UI tal cual se guardó (es lo que rellena el toggle).
        $this->getJson("/api/customers/{$profile->user_id}")
            ->assertOk()
            ->assertJsonPath('notify_invoice', false);
    }

    #[Test]
    public function the_preference_survives_an_edit_and_comes_back_to_the_ui(): void
    {
        ['tenant' => $tenant, 'customer' => $customer] = $this->scenario(notifyInvoice: true);
        Sanctum::actingAs($this->userWith($tenant, [Permissions::EDIT_INTERNET_SERVICE, Permissions::VIEW_CLIENTS]));

        $this->putJson("/api/customers/{$customer->id}", ['notify_invoice' => false])
            ->assertOk();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $customer->id)->first()->notify_invoice);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('notify_invoice', false);
    }

    /**
     * Una actualización que NO menciona el campo no puede resucitar el aviso:
     * el operador que corrige una dirección no está pidiendo volver a notificar.
     */
    #[Test]
    public function a_partial_update_that_omits_the_field_preserves_it(): void
    {
        ['tenant' => $tenant, 'customer' => $customer] = $this->scenario(notifyInvoice: false);
        Sanctum::actingAs($this->userWith($tenant, [Permissions::EDIT_INTERNET_SERVICE, Permissions::VIEW_CLIENTS]));

        $this->putJson("/api/customers/{$customer->id}", ['address' => 'CL 28C 423'])
            ->assertOk();

        $this->assertFalse(
            (bool) CustomerProfile::where('user_id', $customer->id)->first()->notify_invoice,
            'Una edición parcial no puede reactivar el aviso por omisión.'
        );
    }

    // ── 2. Separación respecto de «No facturar a este cliente» ───────

    #[Test]
    public function silencing_notifications_does_not_exclude_the_customer_from_billing(): void
    {
        ['customer' => $customer] = $this->scenario(notifyInvoice: false);

        $profile = CustomerProfile::where('user_id', $customer->id)->first();

        $this->assertFalse((bool) $profile->notify_invoice);
        $this->assertFalse(
            (bool) $profile->exclude_from_billing,
            'Silenciar el aviso NO puede sacar al cliente del ciclo de facturación.'
        );
        $this->assertTrue((bool) $profile->status, 'Ni tocar el estado del servicio.');
    }

    /**
     * El corazón del pedido del cliente: «quiero mis facturas, sin los mensajes».
     * La factura se emite igual y conserva su saldo por cobrar.
     */
    #[Test]
    public function the_monthly_invoice_is_still_generated_for_a_silenced_customer(): void
    {
        ['customer' => $customer, 'invoice' => $invoice] = $this->scenario(notifyInvoice: false);

        $this->assertDatabaseHas('invoices', [
            'id'          => $invoice->id,
            'customer_id' => $customer->id,
            'status'      => 'issued',
        ]);
        $this->assertSame(50000.0, (float) $invoice->fresh()->balance_due);
    }

    // ── 3. Camino automático: aviso de factura nueva ─────────────────

    #[Test]
    public function the_new_invoice_notice_is_sent_when_the_preference_is_on(): void
    {
        ['customer' => $customer, 'invoice' => $invoice, 'profile' => $profile, 'router' => $router]
            = $this->scenario(notifyInvoice: true);

        $this->notifyInvoiceCreated($invoice, $profile, $router);

        Mail::assertSent(InvoiceCreatedMail::class, fn ($m) => $m->hasTo($customer->email));
    }

    #[Test]
    public function the_new_invoice_notice_is_suppressed_when_the_preference_is_off(): void
    {
        ['invoice' => $invoice, 'profile' => $profile, 'router' => $router]
            = $this->scenario(notifyInvoice: false);

        $this->notifyInvoiceCreated($invoice, $profile, $router);

        Mail::assertNothingSent();
    }

    /** Invoca el punto de notificación protegido, que es donde vive el guard. */
    private function notifyInvoiceCreated(Invoice $invoice, CustomerProfile $profile, Router $router): void
    {
        $service = app(\App\Services\BillingService::class);
        $metodo  = new \ReflectionMethod($service, 'notifyInvoiceCreated');
        $metodo->setAccessible(true);
        $metodo->invoke($service, $invoice->fresh(), $profile->fresh(), $router->billingConfig);
    }

    // ── 4. Camino automático: recordatorios del scheduler ────────────

    #[Test]
    public function the_scheduled_reminder_is_sent_when_the_preference_is_on(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        ['customer' => $customer] = $this->scenario(notifyInvoice: true);

        $stats = app(PaymentReminderService::class)->sendDueReminders();

        $this->assertSame(1, $stats['reminded']);
        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($customer->email));
    }

    #[Test]
    public function the_scheduled_reminder_is_suppressed_when_the_preference_is_off(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        ['invoice' => $invoice] = $this->scenario(notifyInvoice: false);

        $stats = app(PaymentReminderService::class)->sendDueReminders();

        $this->assertSame(0, $stats['reminded']);
        Mail::assertNothingSent();

        // Y no se marca como avisada una factura que nadie avisó: si mañana el
        // cliente vuelve a pedir los mensajes, el ciclo no quedó "consumido".
        $this->assertNull(
            $invoice->fresh()->last_reminder_sent,
            'Un envío omitido no puede quedar registrado como enviado.'
        );
    }

    // ── 5. Camino manual: UNA factura (excepción documentada) ────────

    /**
     * Excepción deliberada: un agente que abre una factura concreta y pulsa
     * «enviar recordatorio» está tomando una decisión puntual sobre ESE caso.
     * Se fija como comportamiento esperado para que nadie lo "arregle" sin
     * darse cuenta de que era intencional (BITACORA_TECNICA § del 2026-08-05).
     */
    #[Test]
    public function a_single_manual_reminder_is_an_intentional_exception_and_still_sends(): void
    {
        ['tenant' => $tenant, 'customer' => $customer, 'invoice' => $invoice]
            = $this->scenario(notifyInvoice: false);

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $this->postJson("/api/billing/invoices/{$invoice->id}/send-reminder")
            ->assertOk();

        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($customer->email));
    }

    // ── 6. Camino manual: EN MASA ───────────────────────────────────

    /**
     * El envío masivo NO es una decisión puntual sobre un cliente: el operador
     * marca casillas en el listado (o «seleccionar todo») y dispara. El cliente
     * que pidió no recibir mensajes recibe uno igual.
     *
     * Es justo lo que el manual de usuario promete que NO pasa: «sólo apaga el
     * aviso de correo/WhatsApp de factura nueva y los recordatorios de pago».
     */
    #[Test]
    public function a_bulk_reminder_respects_the_preference(): void
    {
        ['tenant' => $tenant, 'invoice' => $silenciada] = $this->scenario(notifyInvoice: false);

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$silenciada->id],
        ])->assertOk();

        Mail::assertNothingSent();
        $this->assertNull(
            $silenciada->fresh()->last_reminder_sent,
            'Un envío omitido no puede quedar registrado como enviado.'
        );
    }

    /**
     * Un omitido NO es un fallo. Si se contara como fallido, el operador vería
     * «1 fallido» y se pondría a investigar una avería que no existe; y si el
     * lote entero fueran clientes silenciados, la pantalla cantaría error rojo
     * sobre una operación que hizo exactamente lo que debía.
     */
    #[Test]
    public function a_skipped_reminder_is_reported_as_skipped_not_as_failed(): void
    {
        ['tenant' => $tenant, 'invoice' => $silenciada] = $this->scenario(notifyInvoice: false);

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$silenciada->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('summary.success', 0)
            ->assertJsonPath("results.{$silenciada->id}.skipped", true)
            ->assertJsonPath("results.{$silenciada->id}.reason", 'notify_invoice_disabled');
    }

    /** El motivo que se devuelve no puede llevar datos de contacto del cliente. */
    #[Test]
    public function the_skip_trace_carries_no_contact_details(): void
    {
        ['tenant' => $tenant, 'customer' => $customer, 'invoice' => $silenciada]
            = $this->scenario(notifyInvoice: false);

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $cuerpo = $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$silenciada->id],
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString($customer->email, $cuerpo);
    }

    /** Un envío masivo mixto: sólo se salta a quien lo pidió. */
    #[Test]
    public function a_bulk_reminder_still_reaches_customers_who_did_not_opt_out(): void
    {
        ['tenant' => $tenant, 'invoice' => $silenciada] = $this->scenario(notifyInvoice: false);
        ['customer' => $normal, 'invoice' => $suya]     = $this->scenario(notifyInvoice: true, tenant: $tenant);

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$silenciada->id, $suya->id],
        ])->assertOk();

        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($normal->email));
        Mail::assertSentCount(1);
        $this->assertNotNull($suya->fresh()->last_reminder_sent);
    }

    /**
     * «No facturar a este cliente» saca al cliente de TODO el ciclo automático,
     * avisos incluidos. Un envío masivo tampoco puede saltarse eso.
     */
    #[Test]
    public function a_bulk_reminder_skips_customers_excluded_from_billing(): void
    {
        ['tenant' => $tenant, 'invoice' => $invoice] = $this->scenario(
            notifyInvoice: true,
            profileOverrides: ['exclude_from_billing' => true],
        );

        Sanctum::actingAs($this->userWith($tenant, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$invoice->id],
        ])->assertOk();

        Mail::assertNothingSent();
    }

    // ── 7. Aislamiento por tenant ───────────────────────────────────

    #[Test]
    public function the_preference_is_read_from_the_customer_of_the_invoice_not_a_shared_default(): void
    {
        ['invoice' => $silenciada] = $this->scenario(notifyInvoice: false);
        ['tenant' => $tenantB, 'customer' => $clienteB, 'invoice' => $suya] = $this->scenario(notifyInvoice: true);

        // Un operador de la sede B manda su propio recordatorio: la preferencia
        // del cliente silenciado de OTRA sede no puede afectarle.
        Sanctum::actingAs($this->userWith($tenantB, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$suya->id],
        ])->assertOk();

        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($clienteB->email));
        Mail::assertSentCount(1);
    }

    /**
     * Sonda de aislamiento: un operador de la sede A no debería poder disparar
     * recordatorios sobre facturas de la sede B pasando sus ids a mano.
     */
    #[Test]
    public function an_operator_cannot_bulk_remind_invoices_from_another_tenant(): void
    {
        ['tenant' => $tenantA] = $this->scenario(notifyInvoice: true);
        ['customer' => $clienteB, 'invoice' => $ajena] = $this->scenario(notifyInvoice: true);

        Sanctum::actingAs($this->userWith($tenantA, Permissions::VIEW_BILLING));

        $this->postJson('/api/billing/invoices/bulk-reminders', [
            'invoice_ids' => [$ajena->id],
        ]);

        Mail::assertNotSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($clienteB->email));
    }

    #[Test]
    public function silencing_one_customer_does_not_silence_another(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));
        $this->scenario(notifyInvoice: false);
        ['customer' => $ruidoso] = $this->scenario(notifyInvoice: true);

        $stats = app(PaymentReminderService::class)->sendDueReminders();

        $this->assertSame(1, $stats['reminded']);
        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($ruidoso->email));
        Mail::assertSentCount(1);
    }
}
