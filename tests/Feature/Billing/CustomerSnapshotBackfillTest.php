<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El relleno de `customer_name` / `customer_document` en filas que YA existían.
 *
 * POR QUÉ ESTA SUITE EXISTE APARTE
 *
 * `BillingHistorySurvivesCustomerDeletionTest` cubre el trait
 * `FreezesCustomerSnapshot`: el snapshot que se congela al CREAR la fila. Lo que
 * no cubría nadie es el otro camino, el de la migración `2026_09_09_000002`:
 * las decenas de miles de facturas y pagos que ya estaban en la base cuando se
 * añadieron las dos columnas.
 *
 * Y ese camino tiene DOS implementaciones distintas desde el 2026-09-10:
 *
 *   pgsql   un solo `UPDATE ... FROM`
 *   sqlite  el recorrido original en PHP, fila a fila
 *
 * La segunda versión nació porque la primera tardaba minutos contra el pooler de
 * Supabase (~258 ms por ida y vuelta, un UPDATE por titular) y tumbó dos
 * despliegues seguidos por health check — ver § 60 de la bitácora. Tener dos
 * implementaciones del mismo criterio es exactamente la clase de cosa que se
 * desincroniza en silencio, así que estas pruebas corren contra las dos: en el
 * job rápido de SQLite ejercitan el bucle, y en el job «PostgreSQL, motor real»
 * del CI ejercitan el SQL. Si divergen, falla una de las dos.
 *
 * CÓMO SE SIMULA UNA FILA «DE ANTES»
 *
 * No se puede insertar una factura sin snapshot por el camino normal: el trait
 * lo rellena al crear. Se crea con el trait y después se vacían las dos columnas
 * con una escritura directa, que es exactamente el estado en que la migración se
 * encuentra las filas históricas. Después se vuelve a llamar a `up()`, que es
 * idempotente: las columnas se añaden sólo si faltan y el relleno filtra por
 * `customer_name IS NULL`.
 */
class CustomerSnapshotBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    /** La migración, para poder volver a correr su `up()`. */
    private function migracion(): Migration
    {
        return require database_path(
            'migrations/2026_09_09_000002_preserve_billing_history_on_customer_deletion.php'
        );
    }

    private function cliente(array $usuario = [], ?array $perfil = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => 'Axel',
            'user_lastname' => 'Cano',
        ], $usuario));

        if ($perfil !== null) {
            CustomerProfile::create(array_merge([
                'user_id' => $user->id,
                'name'    => 'Axel',
                'last_name' => 'Cano',
                'cedula'  => '1234567890',
                'status'  => true,
            ], $perfil));
        }

        return $user;
    }

    private function factura(User $cliente): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $cliente->id,
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => now()->toDateString(),
            'due_date'     => now()->addDays(15)->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end'   => now()->endOfMonth()->toDateString(),
            'subtotal'     => 50000,
            'total'        => 50000,
            'balance_due'  => 50000,
            'status'       => 'issued',
        ]);
    }

    /** Deja la fila como estaba ANTES de que existiera el snapshot. */
    private function vaciarSnapshot(string $tabla, int $id): void
    {
        DB::table($tabla)->where('id', $id)->update([
            'customer_name'     => null,
            'customer_document' => null,
        ]);
    }

    private function snapshot(string $tabla, int $id): object
    {
        return DB::table($tabla)->where('id', $id)->first(['customer_name', 'customer_document']);
    }

    #[Test]
    public function rellena_nombre_y_documento_de_una_factura_historica(): void
    {
        $factura = $this->factura($this->cliente());
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        $fila = $this->snapshot('invoices', $factura->id);
        $this->assertSame('Axel Cano', $fila->customer_name);
        $this->assertSame('1234567890', $fila->customer_document);
    }

    #[Test]
    public function rellena_tambien_los_pagos(): void
    {
        $cliente = $this->cliente();
        $pago = Payment::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $cliente->id,
            'amount'         => 50000,
            'payment_date'   => now()->toDateString(),
            'payment_method' => 'efectivo',
        ]);

        $this->vaciarSnapshot('payments', $pago->id);

        $this->migracion()->up();

        $fila = $this->snapshot('payments', $pago->id);
        $this->assertSame('Axel Cano', $fila->customer_name);
        $this->assertSame('1234567890', $fila->customer_document);
    }

    #[Test]
    public function si_el_usuario_no_tiene_nombre_lo_toma_del_perfil(): void
    {
        $cliente = $this->cliente(
            ['user_name' => '', 'user_lastname' => ''],
            ['name' => 'Eddy', 'last_name' => 'Cubides']
        );
        $factura = $this->factura($cliente);
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        $this->assertSame('Eddy Cubides', $this->snapshot('invoices', $factura->id)->customer_name);
    }

    #[Test]
    public function sin_nombre_en_ningun_sitio_cae_al_correo(): void
    {
        $cliente = $this->cliente(
            ['user_name' => '', 'user_lastname' => '', 'email' => 'sinnombre@ejemplo.com'],
            ['name' => '', 'last_name' => '']
        );
        $factura = $this->factura($cliente);
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        $this->assertSame('sinnombre@ejemplo.com', $this->snapshot('invoices', $factura->id)->customer_name);
    }

    #[Test]
    public function un_apellido_vacio_no_deja_el_nombre_con_espacio_de_sobra(): void
    {
        $cliente = $this->cliente(['user_name' => 'Eddy', 'user_lastname' => ''], ['cedula' => '999']);
        $factura = $this->factura($cliente);
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        // 'Eddy', no 'Eddy ': el recorte va en las dos implementaciones.
        $this->assertSame('Eddy', $this->snapshot('invoices', $factura->id)->customer_name);
    }

    #[Test]
    public function una_fila_ya_rellenada_no_se_pisa(): void
    {
        $factura = $this->factura($this->cliente());

        DB::table('invoices')->where('id', $factura->id)->update([
            'customer_name'     => 'Nombre Congelado',
            'customer_document' => '000',
        ]);

        $this->migracion()->up();

        $fila = $this->snapshot('invoices', $factura->id);
        $this->assertSame('Nombre Congelado', $fila->customer_name);
        $this->assertSame('000', $fila->customer_document);
    }

    #[Test]
    public function sin_nombre_ni_documento_la_fila_se_deja_como_estaba(): void
    {
        // Ni nombre, ni perfil, ni correo: no hay nada que congelar y escribir
        // dos NULL sería indistinguible de no haber pasado por aquí.
        $cliente = $this->cliente(['user_name' => '', 'user_lastname' => '', 'email' => ''], null);
        $factura = $this->factura($cliente);
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        $fila = $this->snapshot('invoices', $factura->id);
        $this->assertNull($fila->customer_name);
        $this->assertNull($fila->customer_document);
    }

    /**
     * El nombre se recorta a 160.
     *
     * El recorte del documento a 40 NO se prueba, y no es un olvido:
     * `customer_profile.cedula` es `varchar(20)`, así que por construcción un
     * documento no puede pasar de 40 y no hay forma de provocar el recorte sin
     * falsear el esquema. El `LEFT(..., 40)` de la migración es defensivo — el
     * margen está pensado para el día que la columna crezca para un NIT con
     * dígito de verificación o una cédula de extranjería.
     *
     * Se intentó con una cédula de 60 caracteres y PostgreSQL la rechazó con
     * `SQLSTATE[22001] value too long for type character varying(20)`. SQLite la
     * aceptaba tan tranquilo, que es la trampa de siempre: el motor de la suite
     * rápida es de tipado dinámico y no valida largos.
     */
    #[Test]
    public function el_nombre_se_recorta_a_160(): void
    {
        $cliente = $this->cliente(
            ['user_name' => str_repeat('A', 200), 'user_lastname' => ''],
            ['cedula' => '1234567890']
        );
        $factura = $this->factura($cliente);
        $this->vaciarSnapshot('invoices', $factura->id);

        $this->migracion()->up();

        $fila = $this->snapshot('invoices', $factura->id);
        $this->assertSame(160, mb_strlen($fila->customer_name));
        $this->assertSame('1234567890', $fila->customer_document);
    }
}
