<?php

/**
 * Proceso hijo de MonthlyInvoiceConcurrencyPostgresTest. NO es una herramienta.
 *
 * Arranca la aplicación en su propio proceso —su propia conexión a PostgreSQL—
 * para que la concurrencia sea real: dos procesos del sistema operativo
 * peleando por la misma fila, no dos llamadas sucesivas en el mismo hilo.
 *
 *   php monthly_invoice_writer.php hold   '{"customer_id":1,"router_id":2,"hold_ms":2500}'
 *   php monthly_invoice_writer.php create '{"customer_id":1,"router_id":2}'
 *
 * hold:   abre una transacción, bloquea la fila del cliente, avisa por stdout
 *         («LOCKED»), espera hold_ms y SÓLO ENTONCES emite la mensualidad.
 *         Simula al escritor que va primero y tarda.
 * create: emite la mensualidad por la vía normal (createMonthlyInvoiceFor).
 *
 * Imprime una línea JSON final con el resultado y el tiempo que tardó.
 */

use App\Billing\MonthlyInvoiceAlreadyExists;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// La misma salvaguarda que tests/TestCase.php: sólo un PostgreSQL desechable.
$conn = DB::connection();
if ($conn->getDriverName() !== 'pgsql'
    || !in_array((string) $conn->getConfig('host'), ['127.0.0.1', 'localhost', '::1', 'postgres'], true)
    || !str_ends_with((string) $conn->getConfig('database'), '_test')) {
    fwrite(STDERR, "Salvaguarda: este script sólo corre contra un PostgreSQL local *_test.\n");
    exit(2);
}

[$modo, $json] = [$argv[1] ?? '', $argv[2] ?? '{}'];
$args = json_decode($json, true);

$billing = app(BillingService::class);
$crear   = function () use ($billing, $args) {
    $router  = Router::withoutGlobalScope('tenant')->with('billingConfig')->findOrFail($args['router_id']);
    $profile = CustomerProfile::where('user_id', $args['customer_id'])->firstOrFail();
    $plan    = UserService::where('user_id', $args['customer_id'])->firstOrFail()->servicePlan;

    $metodo = new ReflectionMethod($billing, 'createMonthlyInvoiceFor');
    $metodo->setAccessible(true);

    return $metodo->invoke(
        $billing,
        (int) $router->tenant_id, (int) $args['customer_id'], $router, $profile, $plan,
        Carbon::create(2026, 9, 15), Carbon::create(2026, 10, 10),
        Carbon::create(2026, 9, 1), Carbon::create(2026, 9, 30),
        $router->billingConfig,
    );
};

$t0 = microtime(true);

try {
    if ($modo === 'hold') {
        $invoice = DB::transaction(function () use ($args, $crear) {
            CustomerProfile::where('user_id', $args['customer_id'])->lockForUpdate()->first();
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            usleep((int) $args['hold_ms'] * 1000);

            return $crear();
        });
    } elseif ($modo === 'create') {
        $invoice = $crear();
    } else {
        fwrite(STDERR, "Modo desconocido: {$modo}\n");
        exit(2);
    }

    $resultado = ['result' => 'created', 'invoice_id' => (int) $invoice->id];
} catch (MonthlyInvoiceAlreadyExists $e) {
    $resultado = ['result' => 'already_exists', 'invoice_id' => (int) $e->invoice->id];
}

$resultado['elapsed_ms'] = (int) round((microtime(true) - $t0) * 1000);

fwrite(STDOUT, 'RESULT ' . json_encode($resultado) . "\n");
