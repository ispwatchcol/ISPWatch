<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de los libros: comprueba, invariante por invariante, que la
 * contabilidad del sistema cierra.
 *
 * NO ESCRIBE NADA. Es un lector puro, pensado para poder correrse contra
 * producción sin pedirle permiso a nadie y sin riesgo de empeorar el descuadre
 * que se está investigando.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * Ya teníamos tres verificadores, y ninguno mira lo que aquí se mira:
 *   · billing:verify-monthly        → ¿se generaron las facturas del mes?
 *   · billing:verify-cuts           → ¿se cortó a quien debía?
 *   · billing:verify-orphan-payments→ ¿sobra dinero recibido? (una sola ecuación,
 *                                     y además mal planteada: ver C5)
 *
 * Que cada factura cuadre con lo que se le aplicó, que el libro de saldo a
 * favor cuadre con su caché, que ningún peso de una empresa haya aterrizado en
 * la factura de otra — eso no lo miraba nadie. Un ISP no puede descubrir un
 * descuadre porque un cliente le traiga un Excel.
 *
 * ── El modelo de dinero que se está verificando ─────────────────────────────
 *
 * Una factura se salda por CUATRO caminos distintos, y sólo uno deja fila en
 * `payment_allocations`:
 *
 *   1. un pago asignado           → payment_allocations.amount
 *   2. saldo a favor aplicado     → customer_credits (type=applied, negativo)
 *   3. el faltante de un abono parcial que se manda al arrastre → carried_out
 *   4. la anulación                → balance_due = 0, carried_out = 0
 *
 * De ahí la ecuación de C1. Quien la ignore (y el verificador viejo la ignora)
 * ve descuadres donde sólo hay saldo a favor funcionando como debe.
 *
 * ── Severidades ─────────────────────────────────────────────────────────────
 *
 * critical → hay plata que no cuadra, o que está en la empresa equivocada.
 * warning  → no hay plata perdida, pero la cifra que ve el cliente puede no
 *            coincidir consigo misma entre dos pantallas.
 * info     → contexto para conciliar contra un Excel; no es un defecto.
 */
class BooksAuditService
{
    /**
     * Bajo esto es ruido de coma flotante sobre decimal(·,2), no un descuadre.
     *
     * OJO: va INTERPOLADA en el SQL (`'… > ' . self::TOLERANCIA`), nunca como
     * binding. PDO manda los float como texto, y SQLite —donde corren las
     * pruebas— ordena todo número por debajo de cualquier texto: `25000 > ?`
     * con 0.01 atado da FALSO. La comprobación no fallaba, se volvía muda, que
     * en un auditor es peor: las pruebas de libros sanos pasaban porque la
     * consulta no devolvía nunca nada.
     *
     * Es constante de clase y no dato de fuera: no hay nada que inyectar.
     */
    public const TOLERANCIA = 0.01;

    /** Tope de filas de detalle por hallazgo. El total SIEMPRE se calcula sobre todo. */
    public const MAX_DETALLE = 200;

    /**
     * Corre las trece comprobaciones y devuelve sólo las que encontraron algo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(?int $tenantId = null): array
    {
        $hallazgos = [];

        foreach ([
            'ecuacionDeLaFactura',
            'facturaAnuladaConSaldo',
            'composicionDeLaFactura',
            'estadoIncoherenteConElSaldo',
            'pagoSobreAsignado',
            'cajaDelCliente',
            'libroDeSaldoVsCache',
            'fugaEntreEmpresas',
            'asignacionesHuerfanas',
            'pagosQueElPanelNoVe',
            'dineroSinTitular',
            'numerosDeFacturaRepetidos',
            'arrastreIncoherente',
            'posiblesPagosDuplicados',
        ] as $check) {
            $r = $this->{$check}($tenantId);

            if (($r['count'] ?? 0) > 0) {
                $hallazgos[] = $r;
            }
        }

        return $hallazgos;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C1 · La ecuación de la factura
    // ─────────────────────────────────────────────────────────────────────────

    /**
     *     balance_due == total − asignado − saldo_aplicado − arrastrado_fuera
     *
     * Es LA invariante del módulo. Si falla, el cliente ve en pantalla que debe
     * algo distinto de lo que realmente debe, y todos los cortes, recordatorios
     * y reportes de cartera que se apoyan en `balance_due` heredan el error.
     *
     * `carried_in` NO entra: el arrastre que cobra esta factura ya se sumó a
     * `total` como un ítem más, así que contarlo otra vez lo duplicaría.
     */
    protected function ecuacionDeLaFactura(?int $tenantId): array
    {
        // Todo va con COALESCE: un NULL en cualquier termino convierte la resta
        // entera en NULL, `NULL > 0.01` no es cierto, y la fila se escaparia en
        // silencio. Una factura con el total o el saldo en NULL es justo la que
        // hay que ver, no la que hay que saltar.
        $saldo    = 'COALESCE(i.balance_due, 0)';
        $esperado = 'COALESCE(i.total, 0) - COALESCE(a.alloc, 0) + COALESCE(c.aplicado, 0) - COALESCE(i.carried_out, 0)';

        $q = $this->invoicesConMovimientos($tenantId)
            // Las anuladas tienen su propia regla (C2): aquí sólo las vivas.
            ->whereNotIn('i.status', Invoice::ESTADOS_ANULADOS)
            ->whereRaw("ABS($saldo - ($esperado)) > " . self::TOLERANCIA);

        return $this->hallazgo(
            codigo: 'C1',
            titulo: 'Facturas cuyo saldo no cuadra con lo que se les aplicó',
            severidad: 'critical',
            explica: 'El saldo guardado en la factura no es el que sale de la cuenta '
                   . '(total − pagos asignados − saldo a favor aplicado − arrastre). '
                   . 'La cifra que ve el cliente está mal en esa factura.',
            query: $q,
            selects: [
                'i.id', 'i.tenant_id', 'i.number', 'i.customer_id', 'i.customer_name',
                'i.status', 'i.total', 'i.balance_due', 'i.carried_out',
            ],
            rawSelects: [
                'COALESCE(a.alloc, 0) as asignado',
                'COALESCE(c.aplicado, 0) as saldo_aplicado',
                "($esperado) as saldo_esperado",
                "($saldo - ($esperado)) as desfase",
            ],
            expresionImporte: "($saldo - ($esperado))",
        );
    }

    /**
     * C2 · Anular deja la factura sin efecto: saldo y arrastre a cero. Si una
     * anulada conserva saldo, sigue pesando en la cartera y en la mora —
     * exactamente lo que anular pretendía evitar.
     */
    protected function facturaAnuladaConSaldo(?int $tenantId): array
    {
        $q = DB::table('invoices as i')
            ->when($tenantId, fn ($x) => $x->where('i.tenant_id', $tenantId))
            ->whereIn('i.status', Invoice::ESTADOS_ANULADOS)
            ->where(function ($x) {
                $x->whereRaw('ABS(COALESCE(i.balance_due, 0)) > ' . self::TOLERANCIA)
                  ->orWhereRaw('ABS(COALESCE(i.carried_out, 0)) > ' . self::TOLERANCIA);
            });

        return $this->hallazgo(
            codigo: 'C2',
            titulo: 'Facturas anuladas que siguen arrastrando saldo',
            severidad: 'critical',
            explica: 'Una factura anulada debe quedar en saldo 0 y arrastre 0. '
                   . 'Con saldo vivo sigue contando en la cartera y puede provocar un corte.',
            query: $q,
            selects: ['i.id', 'i.tenant_id', 'i.number', 'i.customer_id', 'i.customer_name', 'i.status', 'i.total', 'i.balance_due', 'i.carried_out'],
            rawSelects: ['COALESCE(i.balance_due, 0) as desfase'],
            expresionImporte: '(COALESCE(i.balance_due, 0) + COALESCE(i.carried_out, 0))',
        );
    }

    /**
     * C3 · La factura contra sus propios renglones: subtotal == suma de ítems,
     * y total == subtotal + impuesto.
     *
     * Es la comprobación que cualquier cliente hace a mano con una calculadora
     * sobre el PDF, y la primera que le va a llamar la atención.
     */
    protected function composicionDeLaFactura(?int $tenantId): array
    {
        $items = DB::table('invoice_items')
            ->selectRaw('invoice_id, SUM(amount) as items')
            ->groupBy('invoice_id');

        $q = DB::table('invoices as i')
            ->when($tenantId, fn ($x) => $x->where('i.tenant_id', $tenantId))
            ->leftJoinSub($items, 'it', 'it.invoice_id', '=', 'i.id')
            ->where(function ($x) {
                $x->whereRaw('ABS(COALESCE(i.subtotal, 0) - COALESCE(it.items, 0)) > ' . self::TOLERANCIA)
                  ->orWhereRaw('ABS(COALESCE(i.total, 0) - (COALESCE(i.subtotal, 0) + COALESCE(i.tax, 0))) > ' . self::TOLERANCIA);
            });

        return $this->hallazgo(
            codigo: 'C3',
            titulo: 'Facturas que no cuadran con sus propios renglones',
            severidad: 'critical',
            explica: 'El subtotal no es la suma de los ítems, o el total no es subtotal + impuesto. '
                   . 'Es el descuadre que el cliente detecta sumando el PDF a mano.',
            query: $q,
            selects: ['i.id', 'i.tenant_id', 'i.number', 'i.customer_id', 'i.customer_name', 'i.subtotal', 'i.tax', 'i.total'],
            rawSelects: [
                'COALESCE(it.items, 0) as suma_items',
                '(COALESCE(i.subtotal, 0) - COALESCE(it.items, 0)) as desfase',
            ],
            // Los dos descuadres posibles de esta comprobacion, sumados: una
            // factura puede fallar por los items, por el impuesto, o por ambos.
            expresionImporte: '(ABS(COALESCE(i.subtotal, 0) - COALESCE(it.items, 0)) + ABS(COALESCE(i.total, 0) - (COALESCE(i.subtotal, 0) + COALESCE(i.tax, 0))))',
        );
    }

    /**
     * C4 · El estado tiene que decir lo mismo que el saldo.
     *
     * Importa porque media docena de consultas filtran por `status` y otras
     * tantas por `balance_due`: cuando discrepan, dos pantallas del producto
     * cuentan historias distintas del mismo cliente.
     *
     * Las anuladas quedan fuera (su saldo es 0 por definición, C2 las cubre) y
     * los borradores también: un borrador aún no salió al mundo.
     */
    protected function estadoIncoherenteConElSaldo(?int $tenantId): array
    {
        $q = DB::table('invoices as i')
            ->when($tenantId, fn ($x) => $x->where('i.tenant_id', $tenantId))
            ->whereNotIn('i.status', array_merge(Invoice::ESTADOS_ANULADOS, [Invoice::STATUS_DRAFT]))
            ->where(function ($x) {
                // Saldo 0 pero no figura pagada.
                $x->where(function ($y) {
                    $y->whereRaw('ABS(COALESCE(i.balance_due, 0)) <= ' . self::TOLERANCIA)
                      ->where('i.status', '!=', Invoice::STATUS_PAID);
                })
                // Figura pagada pero todavía debe.
                ->orWhere(function ($y) {
                    $y->whereRaw('COALESCE(i.balance_due, 0) > ' . self::TOLERANCIA)
                      ->where('i.status', '=', Invoice::STATUS_PAID);
                })
                // Abonada a medias pero no figura parcial.
                ->orWhere(function ($y) {
                    $y->whereRaw('COALESCE(i.balance_due, 0) > ' . self::TOLERANCIA)
                      ->whereRaw('COALESCE(i.balance_due, 0) < COALESCE(i.total, 0) - ' . self::TOLERANCIA)
                      ->whereNotIn('i.status', [Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE]);
                });
            });

        return $this->hallazgo(
            codigo: 'C4',
            titulo: 'Facturas cuyo estado no coincide con su saldo',
            severidad: 'warning',
            explica: 'El estado y el saldo se contradicen. No hay plata perdida, pero las '
                   . 'pantallas que filtran por estado y las que filtran por saldo muestran '
                   . 'cosas distintas del mismo cliente.',
            query: $q,
            selects: ['i.id', 'i.tenant_id', 'i.number', 'i.customer_id', 'i.customer_name', 'i.status', 'i.total', 'i.balance_due'],
            rawSelects: ['COALESCE(i.balance_due, 0) as desfase'],
            expresionImporte: 'COALESCE(i.balance_due, 0)',
        );
    }

    /**
     * C5 · Ningún pago puede tener asignado más dinero del que entró.
     *
     * Si pasa, ese pago está cubriendo facturas por encima de su importe: hay
     * facturas que figuran pagadas con plata que nunca existió.
     */
    protected function pagoSobreAsignado(?int $tenantId): array
    {
        $alloc = DB::table('payment_allocations')
            ->selectRaw('payment_id, SUM(amount) as alloc')
            ->groupBy('payment_id');

        $q = DB::table('payments as p')
            ->when($tenantId, fn ($x) => $x->where('p.tenant_id', $tenantId))
            ->joinSub($alloc, 'a', 'a.payment_id', '=', 'p.id')
            ->whereRaw('COALESCE(a.alloc, 0) - COALESCE(p.amount, 0) > ' . self::TOLERANCIA);

        return $this->hallazgo(
            codigo: 'C5',
            titulo: 'Pagos con más dinero asignado del que se recibió',
            severidad: 'critical',
            explica: 'Hay facturas saldadas con plata que no entró. Es el descuadre más grave '
                   . 'de todos: infla el recaudo y da por cobradas facturas que no lo están.',
            query: $q,
            selects: ['p.id', 'p.tenant_id', 'p.customer_id', 'p.customer_name', 'p.amount', 'p.payment_date', 'p.reference'],
            rawSelects: ['a.alloc as asignado', '(COALESCE(a.alloc, 0) - COALESCE(p.amount, 0)) as desfase'],
            expresionImporte: '(COALESCE(a.alloc, 0) - COALESCE(p.amount, 0))',
        );
    }

    /**
     * C6 · La caja del cliente. La conservación del efectivo:
     *
     *     recibido == aplicado a facturas + lo que se volvió saldo a favor
     *
     *     SUM(payments.amount) == SUM(payment_allocations) + SUM(credits earned)
     *
     * ── Por qué NO es la ecuación de billing:verify-orphan-payments ──────────
     *
     * Aquel comprueba `recibido == aplicado + credit_balance`, usando el SALDO
     * ACTUAL. Pero cuando el saldo a favor paga una factura, `applyCreditToInvoice()`
     * baja `balance_due` y baja `credit_balance` SIN crear ninguna asignación.
     * A partir de ese momento el dinero no está ni en `payment_allocations` ni
     * en `credit_balance`, y aquella resta da positivo.
     *
     * Es decir: el verificador viejo denuncia a TODO cliente que alguna vez usó
     * su saldo a favor, por el importe que usó. No es un descuadre; es el saldo
     * a favor funcionando exactamente como se diseñó.
     *
     * Aquí se compara contra lo GANADO (`earned`), que es inmutable: un peso que
     * entró o se aplicó a una factura, o se volvió saldo. Lo que después pase con
     * ese saldo es otro libro (C7), no un agujero de caja.
     */
    protected function cajaDelCliente(?int $tenantId): array
    {
        $recibido = DB::table('payments')
            ->when($tenantId, fn ($x) => $x->where('tenant_id', $tenantId))
            // Los pagos sin titular no se le pueden atribuir a nadie (P-43) y
            // el grupo NULL entra como '' en un bigint: un 22P02 en PostgreSQL
            // que se lleva por delante la auditoría entera. Salen por C11.
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, SUM(amount) as recibido')
            ->groupBy('customer_id');

        $aplicado = DB::table('payment_allocations as pa')
            ->join('payments as p2', 'p2.id', '=', 'pa.payment_id')
            ->whereNotNull('p2.customer_id')
            ->selectRaw('p2.customer_id as cid, SUM(pa.amount) as aplicado')
            ->groupBy('p2.customer_id');

        // `earned` MENOS `reversed`. Un asiento ganado no es inmutable: al
        // corregir a la baja un pago se revierte el excedente que aun no
        // consumio ninguna factura, y si solo se sumaran los `earned` ese
        // excedente devuelto seguiria contando como destino de un dinero que
        // ya no esta. `applied` y `adjusted` NO entran: mueven el saldo dentro
        // de su propio libro (eso lo vigila C7), no la caja.
        $ganado = DB::table('customer_credits')
            ->whereIn('type', ['earned', 'reversed'])
            ->selectRaw('customer_id as cid, SUM(amount) as ganado')
            ->groupBy('customer_id');

        $q = DB::query()->fromSub($recibido, 'r')
            ->leftJoinSub($aplicado, 'a', 'a.cid', '=', 'r.customer_id')
            ->leftJoinSub($ganado, 'g', 'g.cid', '=', 'r.customer_id')
            ->leftJoin('customer_profile as cp', 'cp.user_id', '=', 'r.customer_id')
            ->whereRaw('ABS(r.recibido - COALESCE(a.aplicado, 0) - COALESCE(g.ganado, 0)) > ' . self::TOLERANCIA);

        return $this->hallazgo(
            codigo: 'C6',
            titulo: 'Clientes cuyo dinero recibido no está ni aplicado ni en saldo a favor',
            severidad: 'critical',
            explica: 'Entró plata que no respalda ninguna factura y tampoco quedó como saldo a favor. '
                   . 'Causas conocidas: se borró una factura ya pagada sin reaplicar el saldo, o el '
                   . 'cobro de una instalación superó el valor de su factura (el excedente no se acredita).',
            query: $q,
            selects: ['r.customer_id', 'cp.name', 'cp.last_name'],
            rawSelects: [
                'r.recibido',
                'COALESCE(a.aplicado, 0) as aplicado',
                'COALESCE(g.ganado, 0) as a_saldo',
                '(r.recibido - COALESCE(a.aplicado, 0) - COALESCE(g.ganado, 0)) as desfase',
            ],
            expresionImporte: '(r.recibido - COALESCE(a.aplicado, 0) - COALESCE(g.ganado, 0))',
        );
    }

    /**
     * C7 · El libro de saldo a favor contra su caché.
     *
     * `customer_credits` es la verdad y `customer_profile.credit_balance` una
     * caché para pintar listados. El propio modelo declara la invariante:
     * escribir `credit_balance` a pelo la rompe, y entonces el cliente ve un
     * saldo que su extracto no explica.
     */
    protected function libroDeSaldoVsCache(?int $tenantId): array
    {
        $libro = DB::table('customer_credits')
            ->selectRaw('customer_id as cid, SUM(amount) as libro')
            ->groupBy('customer_id');

        $q = DB::table('customer_profile as cp')
            ->when($tenantId, fn ($x) => $x->where('cp.tenant_id', $tenantId))
            ->leftJoinSub($libro, 'l', 'l.cid', '=', 'cp.user_id')
            // Un cliente sin ningún movimiento y con saldo 0 no es un descuadre:
            // es, simplemente, un cliente que nunca tuvo saldo a favor.
            ->where(function ($x) {
                $x->whereNotNull('l.libro')
                  ->orWhereRaw('ABS(COALESCE(cp.credit_balance, 0)) > ' . self::TOLERANCIA);
            })
            ->whereRaw('ABS(COALESCE(cp.credit_balance, 0) - COALESCE(l.libro, 0)) > ' . self::TOLERANCIA);

        return $this->hallazgo(
            codigo: 'C7',
            titulo: 'Saldo a favor que no coincide con su libro de movimientos',
            severidad: 'critical',
            explica: 'El saldo guardado en la ficha del cliente no es la suma de sus movimientos. '
                   . 'El extracto de saldo a favor no va a poder explicar la cifra que ve el cliente.',
            query: $q,
            selects: ['cp.user_id as customer_id', 'cp.tenant_id', 'cp.name', 'cp.last_name', 'cp.credit_balance'],
            rawSelects: [
                'COALESCE(l.libro, 0) as libro',
                '(COALESCE(cp.credit_balance, 0) - COALESCE(l.libro, 0)) as desfase',
            ],
            expresionImporte: '(COALESCE(cp.credit_balance, 0) - COALESCE(l.libro, 0))',
        );
    }

    /**
     * C8 · Fuga entre empresas.
     *
     * Un pago de una empresa asignado a la factura de otra. En un SaaS
     * multiempresa no es un descuadre contable: es una fuga de datos entre
     * clientes, y mueve el recaudo de las dos.
     */
    protected function fugaEntreEmpresas(?int $tenantId): array
    {
        $q = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->when($tenantId, fn ($x) => $x->where(function ($y) use ($tenantId) {
                $y->where('p.tenant_id', $tenantId)->orWhere('i.tenant_id', $tenantId);
            }))
            ->whereNotNull('p.tenant_id')
            ->whereNotNull('i.tenant_id')
            ->whereColumn('p.tenant_id', '!=', 'i.tenant_id');

        return $this->hallazgo(
            codigo: 'C8',
            titulo: 'Pagos aplicados a facturas de OTRA empresa',
            severidad: 'critical',
            explica: 'Un recaudo de una empresa está saldando la factura de otra. Además del '
                   . 'descuadre en ambas, es una fuga de datos entre clientes del SaaS.',
            query: $q,
            selects: ['pa.id', 'pa.amount', 'p.id as payment_id', 'p.tenant_id as tenant_pago', 'i.id as invoice_id', 'i.tenant_id as tenant_factura', 'i.number'],
            rawSelects: ['pa.amount as desfase'],
            expresionImporte: 'pa.amount',
        );
    }

    /**
     * C9 · Asignaciones que apuntan al vacío.
     *
     * `payment_allocations` no tiene borrado en cascada garantizado en todos los
     * caminos. Una asignación cuyo pago o cuya factura ya no existe es dinero
     * contado contra algo que no está.
     */
    protected function asignacionesHuerfanas(?int $tenantId): array
    {
        $q = DB::table('payment_allocations as pa')
            ->leftJoin('payments as p', 'p.id', '=', 'pa.payment_id')
            ->leftJoin('invoices as i', 'i.id', '=', 'pa.invoice_id')
            ->when($tenantId, fn ($x) => $x->where(function ($y) use ($tenantId) {
                $y->where('p.tenant_id', $tenantId)->orWhere('i.tenant_id', $tenantId);
            }))
            ->where(function ($x) {
                $x->whereNull('p.id')->orWhereNull('i.id');
            });

        return $this->hallazgo(
            codigo: 'C9',
            titulo: 'Asignaciones de pago que apuntan a un pago o una factura inexistente',
            severidad: 'critical',
            explica: 'Queda dinero asignado contra algo que ya no existe. Suma en los totales '
                   . 'por asignación y no se puede rastrear a ningún documento.',
            query: $q,
            selects: ['pa.id', 'pa.payment_id', 'pa.invoice_id', 'pa.amount'],
            rawSelects: ['pa.amount as desfase'],
            expresionImporte: 'pa.amount',
        );
    }

    /**
     * C10 · Pagos que el panel de Finanzas no ve.
     *
     * El listado de Recaudos suma TODOS los pagos a propósito («lo que está en
     * la tabla es dinero efectivamente recibido»), pero el panel de Finanzas
     * filtra `status = 'completed'`. Cualquier pago con otro estado sale en una
     * pantalla y no en la otra — y es justo el tipo de diferencia que aparece
     * al comparar el sistema contra un Excel.
     */
    protected function pagosQueElPanelNoVe(?int $tenantId): array
    {
        $q = DB::table('payments as p')
            ->when($tenantId, fn ($x) => $x->where('p.tenant_id', $tenantId))
            ->where(function ($x) {
                $x->whereNull('p.status')->orWhere('p.status', '!=', 'completed');
            });

        return $this->hallazgo(
            codigo: 'C10',
            titulo: "Pagos con estado distinto de 'completed'",
            severidad: 'warning',
            explica: 'El listado de Recaudos los suma y el panel de Finanzas no. Las dos pantallas '
                   . 'muestran recaudos distintos del mismo mes, y esta diferencia aparece al '
                   . 'comparar contra un Excel llevado a mano.',
            query: $q,
            selects: ['p.id', 'p.tenant_id', 'p.customer_id', 'p.customer_name', 'p.amount', 'p.status', 'p.payment_date'],
            rawSelects: ['p.amount as desfase'],
            expresionImporte: 'p.amount',
        );
    }

    /**
     * C11 · Dinero sin titular (P-43).
     *
     * Facturas y pagos sobreviven al borrado del cliente por su valor contable,
     * pero quedan fuera de todo informe agrupado por cliente. No es un error;
     * es plata real que ninguna pantalla por cliente enseña, y hay que sumarla
     * aparte para conciliar.
     */
    protected function dineroSinTitular(?int $tenantId): array
    {
        $q = DB::table('payments as p')
            ->when($tenantId, fn ($x) => $x->where('p.tenant_id', $tenantId))
            ->whereNull('p.customer_id');

        return $this->hallazgo(
            codigo: 'C11',
            titulo: 'Pagos sin titular (cliente dado de baja)',
            severidad: 'info',
            explica: 'Recaudos que sobreviven al borrado de su cliente. Cuentan en el total del '
                   . 'tenant pero no aparecen en ningún informe por cliente: hay que sumarlos '
                   . 'aparte al conciliar contra una planilla llevada por cliente.',
            query: $q,
            selects: ['p.id', 'p.tenant_id', 'p.customer_name', 'p.amount', 'p.payment_date', 'p.reference'],
            rawSelects: ['p.amount as desfase'],
            expresionImporte: 'p.amount',
        );
    }

    /**
     * C12 · Números de factura repetidos dentro de una misma empresa.
     *
     * El número es la identidad fiscal del documento. Repetido, deja de poder
     * citarse: dos documentos distintos responden al mismo nombre.
     */
    protected function numerosDeFacturaRepetidos(?int $tenantId): array
    {
        $q = DB::table('invoices as i')
            ->when($tenantId, fn ($x) => $x->where('i.tenant_id', $tenantId))
            ->whereNotNull('i.number')
            ->where('i.number', '!=', '')
            ->selectRaw('i.tenant_id, i.number, COUNT(*) as veces, SUM(i.total) as desfase')
            ->groupBy('i.tenant_id', 'i.number')
            ->havingRaw('COUNT(*) > 1');

        // Agrupado: no pasa por hallazgo() porque el conteo es de grupos.
        $filas = $q->limit(self::MAX_DETALLE)->get()->map(fn ($r) => (array) $r)->all();
        $todas = (clone $q)->get();

        return [
            'code'     => 'C12',
            'title'    => 'Números de factura repetidos en la misma empresa',
            'severity' => 'critical',
            'explain'  => 'Dos facturas distintas comparten número. El número es la identidad fiscal '
                        . 'del documento: repetido, ninguna de las dos puede citarse sin ambigüedad.',
            'count'    => $todas->count(),
            'amount'   => round((float) $todas->sum('desfase'), 2),
            'rows'     => $filas,
        ];
    }

    /**
     * C13 · Coherencia del arrastre.
     *
     * Un movimiento `applied` sin factura de destino, o un `pending` cuya
     * factura de origen se anuló: en el primer caso el cobro no se puede
     * rastrear, en el segundo se va a cobrar el faltante de una factura que
     * ya no tiene efecto — cobrarle al cliente algo que se le anuló.
     */
    protected function arrastreIncoherente(?int $tenantId): array
    {
        $q = DB::table('invoice_carryovers as ic')
            ->leftJoin('invoices as fi', 'fi.id', '=', 'ic.from_invoice_id')
            ->when($tenantId, fn ($x) => $x->where('ic.tenant_id', $tenantId))
            ->where(function ($x) {
                $x->where(function ($y) {
                    $y->where('ic.status', 'applied')->whereNull('ic.to_invoice_id');
                })->orWhere(function ($y) {
                    $y->where('ic.status', 'pending')->whereIn('fi.status', Invoice::ESTADOS_ANULADOS);
                });
            });

        return $this->hallazgo(
            codigo: 'C13',
            titulo: 'Arrastres de saldo incoherentes',
            severidad: 'critical',
            explica: 'Un arrastre marcado como cobrado sin decir en qué factura, o uno pendiente '
                   . 'cuya factura de origen fue anulada (se le va a cobrar al cliente el faltante '
                   . 'de una factura que ya no tiene efecto).',
            query: $q,
            selects: ['ic.id', 'ic.tenant_id', 'ic.customer_id', 'ic.from_invoice_id', 'ic.to_invoice_id', 'ic.status', 'ic.amount', 'fi.status as estado_origen'],
            rawSelects: ['ic.amount as desfase'],
            expresionImporte: 'ic.amount',
        );
    }

    /**
     * C14 · Posibles pagos duplicados: mismo cliente, mismo importe, mismo día.
     *
     * No es necesariamente un defecto —un cliente puede pagar dos servicios el
     * mismo día por el mismo valor—, pero es la forma que toma el error de
     * digitación más común en caja, y es una de las primeras cosas a descartar
     * cuando la planilla del cliente y el sistema no coinciden.
     */
    protected function posiblesPagosDuplicados(?int $tenantId): array
    {
        $q = DB::table('payments as p')
            ->when($tenantId, fn ($x) => $x->where('p.tenant_id', $tenantId))
            ->whereNotNull('p.customer_id')
            ->selectRaw('p.customer_id, p.payment_date, p.amount, COUNT(*) as veces, SUM(p.amount) as total_grupo')
            ->groupBy('p.customer_id', 'p.payment_date', 'p.amount')
            ->havingRaw('COUNT(*) > 1');

        $todas = $q->get();

        // El importe expuesto es lo que sobraría si cada grupo fuese un solo
        // pago digitado de más: total del grupo menos un pago.
        $expuesto = $todas->sum(fn ($r) => (float) $r->total_grupo - (float) $r->amount);

        return [
            'code'     => 'C14',
            'title'    => 'Pagos repetidos el mismo día por el mismo importe',
            'severity' => 'warning',
            'explain'  => 'Mismo cliente, mismo día, mismo valor, más de una vez. Puede ser legítimo, '
                        . 'pero es la forma del error de digitación de caja más habitual y lo primero '
                        . 'que hay que descartar cuando la planilla del cliente no coincide.',
            'count'    => $todas->count(),
            'amount'   => round((float) $expuesto, 2),
            'rows'     => $todas->take(self::MAX_DETALLE)->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infraestructura
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Facturas con lo aplicado por pagos y por saldo a favor colgado al lado.
     *
     * Los dos son subconsultas agrupadas y no un join directo: unir las dos
     * tablas de una vez multiplica las filas (una factura con 2 pagos y 2
     * movimientos de saldo daría 4) y cada importe se contaría de más.
     */
    protected function invoicesConMovimientos(?int $tenantId)
    {
        $alloc = DB::table('payment_allocations')
            ->selectRaw('invoice_id, SUM(amount) as alloc')
            ->groupBy('invoice_id');

        $credito = DB::table('customer_credits')
            ->where('type', 'applied')
            ->whereNotNull('to_invoice_id')
            ->selectRaw('to_invoice_id, SUM(amount) as aplicado')
            ->groupBy('to_invoice_id');

        return DB::table('invoices as i')
            ->when($tenantId, fn ($x) => $x->where('i.tenant_id', $tenantId))
            ->leftJoinSub($alloc, 'a', 'a.invoice_id', '=', 'i.id')
            ->leftJoinSub($credito, 'c', 'c.to_invoice_id', '=', 'i.id');
    }

    /**
     * Arma un hallazgo: cuenta e importa sobre TODAS las filas, y sólo recorta
     * el detalle. Un informe que dijera «200 casos» porque ése es el tope del
     * detalle sería peor que no tenerlo.
     */
    protected function hallazgo(
        string $codigo,
        string $titulo,
        string $severidad,
        string $explica,
        $query,
        array $selects,
        array $rawSelects,
        string $expresionImporte,
    ): array {
        // Va la EXPRESIÓN, no el alias: el alias que le pusimos en el detalle
        // (`... as desfase`) no existe en esta otra consulta, y el agregado
        // reventaría contra una columna desconocida.
        $filas = (clone $query)
            ->selectRaw(implode(', ', array_merge($selects, $rawSelects)))
            ->orderByRaw("ABS($expresionImporte) DESC")
            ->limit(self::MAX_DETALLE)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $agregado = (clone $query)->selectRaw(
            "COUNT(*) as n, COALESCE(SUM(ABS($expresionImporte)), 0) as importe"
        )->first();

        return [
            'code'     => $codigo,
            'title'    => $titulo,
            'severity' => $severidad,
            'explain'  => $explica,
            'count'    => (int) ($agregado->n ?? 0),
            'amount'   => round((float) ($agregado->importe ?? 0), 2),
            'rows'     => $filas,
        ];
    }
}
