<?php

namespace App\Billing;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Plan;
use Carbon\Carbon;

/**
 * "Primera factura": qué se le cobra a un cliente durante sus primeros meses de
 * servicio, cuando su alta NO coincide con el inicio del ciclo de facturación.
 *
 * Son dos decisiones independientes que antes estaban mezcladas en el servicio
 * de facturación y que aquí viven juntas para poder reutilizarse (generación
 * mensual, auditoría y la vista previa del formulario usan ESTA clase; no hay
 * una segunda copia de la fórmula en ningún otro lado):
 *
 *   mode        qué se cobra el mes en que arranca el servicio
 *                 none     → nada; su primera factura sale el ciclo siguiente
 *                 prorated → sólo los días que quedan del mes
 *                 full     → el mes completo, como a un cliente antiguo
 *
 *   freeMonths  cuántos meses POSTERIORES al de instalación van en cortesía.
 *                 0 → ninguno (comportamiento histórico)
 *                 1 → el mes siguiente sale en cero
 *                 N → los N meses siguientes salen en cero
 *
 * Combinándolos salen los casos que el operador vende como producto:
 *
 *   prorated + 0  cobro proporcional y a partir de ahí normal (lo más común)
 *   prorated + 1  instalado el 16/07: paga del 16 al 31 de julio, AGOSTO gratis
 *                 porque lo cubre lo que pagó de instalación, y septiembre
 *                 vuelve a la tarifa plena
 *   none     + 1  "instálate hoy y no pagas hasta dentro de dos meses"
 *   full     + 2  cobra el mes entero pero regala los dos siguientes
 *
 * Los dos ejes se resuelven por separado en cascada CLIENTE → PLAN → ROUTER,
 * así un cliente puede heredar la promoción de su plan pero llevar un modo de
 * cobro distinto (o al revés) sin tener que repetir la configuración entera.
 */
final class FirstInvoicePolicy
{
    /** Tope defensivo: una promoción más larga que un año es casi seguro un dedazo. */
    public const MAX_FREE_MONTHS = 24;

    /** De dónde salió cada eje. Sólo informativo (vista previa y logs). */
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_PLAN     = 'plan';
    public const SOURCE_ROUTER   = 'router';
    public const SOURCE_DEFAULT  = 'default';

    public function __construct(
        public readonly string $mode,
        public readonly int $freeMonths,
        public readonly string $modeSource = self::SOURCE_DEFAULT,
        public readonly string $freeMonthsSource = self::SOURCE_DEFAULT,
    ) {
    }

    /**
     * Resuelve la política efectiva. Cada eje se busca por separado, del nivel
     * más específico al más general, y el primero que traiga un valor gana.
     * Un nivel "sin configurar" (null / cadena vacía) NO cuenta como respuesta:
     * hereda. Para el modo eso significa que "no cobrar" hay que elegirlo
     * explícitamente, no es lo mismo que dejarlo en blanco.
     */
    public static function resolve(
        ?CustomerProfile $profile = null,
        ?Plan $plan = null,
        ?Billing $billingConfig = null,
    ): self {
        [$mode, $modeSource] = self::firstPresent([
            [self::SOURCE_CUSTOMER, $profile?->first_invoice_mode],
            [self::SOURCE_PLAN,     $plan?->first_invoice_mode],
            [self::SOURCE_ROUTER,   $billingConfig?->first_invoice_policy],
        ], Billing::FIRST_INVOICE_NONE);

        [$freeMonths, $freeSource] = self::firstPresent([
            [self::SOURCE_CUSTOMER, $profile?->first_invoice_free_months],
            [self::SOURCE_PLAN,     $plan?->first_invoice_free_months],
            [self::SOURCE_ROUTER,   $billingConfig?->first_invoice_free_months],
        ], 0);

        return new self(
            mode: in_array($mode, Billing::FIRST_INVOICE_MODES, true) ? $mode : Billing::FIRST_INVOICE_NONE,
            freeMonths: self::clampFreeMonths($freeMonths),
            modeSource: $modeSource,
            freeMonthsSource: $freeSource,
        );
    }

    /**
     * Qué cobrarle a este cliente por el periodo [$periodStart, $periodEnd].
     *
     * Devuelve null cuando NO debe existir factura (la política dice no cobrar,
     * o el servicio todavía no había arrancado). Un mes de cortesía SÍ devuelve
     * un cargo, en cero: la factura se emite igual para que quede constancia de
     * la promoción y para que la auditoría mensual no la lea como una factura
     * que faltó.
     *
     * @param  Carbon|null $serviceStart Día real en que arrancó el servicio.
     * @return array{period_start: Carbon, amount: float, description: string, free: bool}|null
     */
    public function chargeFor(
        ?Carbon $serviceStart,
        Carbon $periodStart,
        Carbon $periodEnd,
        float $fullAmount,
        string $planName,
    ): ?array {
        $fullMonth = [
            'period_start' => $periodStart->copy(),
            'amount'       => $fullAmount,
            'description'  => "Servicio mensual: {$planName}",
            'free'         => false,
        ];

        // Sin ninguna señal de cuándo arrancó, se le trata como cliente antiguo:
        // preferimos cobrar de más y que el operador ajuste, antes que dejarlo
        // silenciosamente sin factura.
        if (!$serviceStart) {
            return $fullMonth;
        }

        $elapsedMonths = self::monthsBetween($serviceStart, $periodStart);

        // El periodo es ANTERIOR al arranque del servicio (pasa en cobro
        // vencido, que factura el mes ya consumido): no hay nada que cobrar.
        if ($elapsedMonths < 0) {
            return null;
        }

        // Mes de instalación: manda el modo.
        if ($elapsedMonths === 0) {
            // Arrancó el primer día del periodo (o antes): es un mes completo
            // normal, la política de primera factura no tiene nada que decidir.
            if ($serviceStart->lte($periodStart)) {
                return $fullMonth;
            }

            return $this->installationMonthCharge($serviceStart, $periodStart, $fullAmount, $planName);
        }

        // Meses siguientes: ¿todavía cae dentro de la ventana de cortesía?
        if ($elapsedMonths <= $this->freeMonths) {
            return [
                'period_start' => $periodStart->copy(),
                'amount'       => 0.0,
                'description'  => "Mes de cortesía por instalación ({$elapsedMonths} de {$this->freeMonths}): {$planName}",
                'free'         => true,
            ];
        }

        return $fullMonth;
    }

    /** ¿Este periodo cae dentro de la cortesía posterior a la instalación? */
    public function isFreeMonth(?Carbon $serviceStart, Carbon $periodStart): bool
    {
        if (!$serviceStart || $this->freeMonths < 1) {
            return false;
        }

        $elapsed = self::monthsBetween($serviceStart, $periodStart);

        return $elapsed >= 1 && $elapsed <= $this->freeMonths;
    }

    /**
     * Cobro del mes en que se instaló el cliente.
     *
     * El prorrateo usa la aritmética que el operador ya venía haciendo a mano:
     * instalado el día 20 de un mes de 30 ⇒ 10 días (30 − 20), es decir el día
     * de instalación no se cobra.
     *
     * @return array{period_start: Carbon, amount: float, description: string, free: bool}|null
     */
    private function installationMonthCharge(
        Carbon $serviceStart,
        Carbon $periodStart,
        float $fullAmount,
        string $planName,
    ): ?array {
        if ($this->mode === Billing::FIRST_INVOICE_FULL) {
            return [
                'period_start' => $periodStart->copy(),
                'amount'       => $fullAmount,
                'description'  => "Servicio mensual: {$planName}",
                'free'         => false,
            ];
        }

        if ($this->mode !== Billing::FIRST_INVOICE_PRORATED) {
            return null; // 'none' (por defecto)
        }

        $daysInMonth  = $periodStart->daysInMonth;
        $billableDays = $daysInMonth - $serviceStart->day;
        $amount       = self::prorate($fullAmount, $serviceStart, $periodStart);

        if ($amount <= 0) {
            return null; // instalado el último día del mes / plan sin costo
        }

        return [
            'period_start' => $serviceStart->copy(),
            'amount'       => $amount,
            'description'  => "Servicio proporcional: {$planName} "
                . "({$billableDays} de {$daysInMonth} días — desde el {$serviceStart->format('d/m/Y')})",
            'free'         => false,
        ];
    }

    /**
     * Cobro proporcional a los días que quedan del mes.
     *
     * Es LA fórmula de prorrateo del sistema: la usa la primera factura del plan
     * y también los servicios adicionales recurrentes que arrancan a mitad de
     * mes. Vive aquí, pública y en un solo sitio, para que ajustarla (el
     * redondeo, o si el día de alta se cobra o no) siga siendo un cambio de una
     * línea y no una cacería por el código.
     *
     * Aritmética heredada de lo que el operador ya hacía a mano: alta el día 20
     * de un mes de 30 ⇒ 10 días (30 − 20), es decir el día de alta no se cobra.
     *
     * Devuelve 0.0 cuando no hay nada que cobrar (alta el último día del mes, o
     * importe base en cero); el llamante decide si eso significa "sin factura",
     * "sin ítem" o cualquier otra cosa.
     */
    public static function prorate(float $fullAmount, Carbon $start, Carbon $periodStart): float
    {
        $daysInMonth  = $periodStart->daysInMonth;
        $billableDays = $daysInMonth - $start->day;

        if ($billableDays <= 0 || $fullAmount <= 0) {
            return 0.0;
        }

        // A pesos enteros: la moneda es COP (sin centavos en la práctica) y así
        // el monto coincide exacto con el que la vista previa le muestra al
        // operador antes de crear el cliente.
        return (float) round($fullAmount * $billableDays / $daysInMonth);
    }

    /**
     * Meses de calendario entre dos fechas (negativo si $to es anterior).
     *
     * Se calcula sobre año/mes en vez de con diffInMonths() porque lo que
     * importa es el salto de MES, no si pasaron 30 días: del 31 de enero al 1
     * de febrero hay un mes de diferencia para la facturación, aunque sea un día.
     */
    private static function monthsBetween(Carbon $from, Carbon $to): int
    {
        return ($to->year - $from->year) * 12 + ($to->month - $from->month);
    }

    /**
     * Primer valor "presente" de la cascada, con su origen.
     *
     * @param  array<int,array{0:string,1:mixed}> $candidates
     * @return array{0:mixed,1:string}
     */
    private static function firstPresent(array $candidates, mixed $fallback): array
    {
        foreach ($candidates as [$source, $value]) {
            if ($value !== null && $value !== '') {
                return [$value, $source];
            }
        }

        return [$fallback, self::SOURCE_DEFAULT];
    }

    private static function clampFreeMonths(mixed $value): int
    {
        return max(0, min(self::MAX_FREE_MONTHS, (int) $value));
    }

    /** Representación para la vista previa del formulario y para los logs. */
    public function toArray(): array
    {
        return [
            'mode'               => $this->mode,
            'free_months'        => $this->freeMonths,
            'mode_source'        => $this->modeSource,
            'free_months_source' => $this->freeMonthsSource,
        ];
    }
}
