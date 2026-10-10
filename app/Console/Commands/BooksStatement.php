<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BooksStatementService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Extracto conciliable de un mes, para poner al lado de la planilla del cliente.
 *
 * `--target=` es lo que resuelve la discusión: se le pasa el desfase que el
 * cliente reclama y el comando señala qué diferencia de criterio vale
 * exactamente eso. Si alguna coincide, el desfase es de criterio y no hay nada
 * roto. Si ninguna, el problema es nuestro y toca `billing:audit-books`.
 *
 * NO ESCRIBE NADA.
 */
class BooksStatement extends Command
{
    /**
     * php artisan billing:statement --tenant=19 --month=2026-08
     * php artisan billing:statement --tenant=19 --month=2026-08 --target=3000000
     * php artisan billing:statement --tenant=19 --since=2026-01 --target=3000000
     */
    protected $signature = 'billing:statement
                            {--tenant= : Empresa (obligatorio)}
                            {--month= : Mes en formato YYYY-MM (por defecto, el mes actual)}
                            {--since= : Recorrer desde este mes hasta --month, acumulando}
                            {--target= : Importe reclamado; marca las diferencias que lo expliquen}
                            {--tolerance=1000 : Margen para dar por explicado el --target}
                            {--json : Volcar el extracto en JSON}';

    protected $description = 'Extracto conciliable de un mes: las cifras bajo todos los criterios y el valor de cada diferencia, para cuadrar contra la planilla del cliente. No modifica nada.';

    public function __construct(protected BooksStatementService $statement)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');

        if (!$tenantId) {
            $this->error('Falta --tenant. Ej: --tenant=19');
            return Command::FAILURE;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (!$tenant) {
            $this->error("No existe la empresa #{$tenantId}.");
            return Command::FAILURE;
        }

        try {
            $hasta = $this->option('month')
                ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
                : Carbon::now()->startOfMonth();

            $desde = $this->option('since')
                ? Carbon::createFromFormat('Y-m', $this->option('since'))->startOfMonth()
                : $hasta->copy();
        } catch (\Throwable $e) {
            $this->error('Mes inválido. Usa el formato YYYY-MM (ej: 2026-08).');
            return Command::FAILURE;
        }

        if ($desde->gt($hasta)) {
            $this->error('--since no puede ser posterior a --month.');
            return Command::FAILURE;
        }

        $meses = [];
        for ($m = $desde->copy(); $m->lte($hasta); $m->addMonth()) {
            $meses[] = $this->statement->forMonth($tenantId, $m->copy());
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tenant_id' => $tenantId,
                'months'    => $meses,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $nombre = $tenant->trade_name ?: ($tenant->legal_name ?: "empresa #{$tenantId}");
        $rango  = count($meses) > 1
            ? $meses[0]['period']['month'] . ' → ' . end($meses)['period']['month']
            : $meses[0]['period']['month'];

        $this->line('');
        $this->line("  Extracto conciliable — {$nombre} — {$rango}");
        $this->line('');

        // Con varios meses las cifras se acumulan: un desfase que el cliente
        // arrastra desde enero no se ve en ningún mes suelto.
        $acum = $this->acumular($meses);

        foreach (['facturado' => 'FACTURADO', 'recaudado' => 'RECAUDADO', 'cartera' => 'CARTERA Y SALDOS'] as $k => $titulo) {
            // La cartera es un saldo a hoy, no un flujo: acumularla la
            // multiplicaria por el numero de meses. Se toma la del ultimo mes.
            $set = $k === 'cartera' ? end($meses)['cartera'] : $acum[$k];

            $this->line("  <options=bold>{$titulo}</>");
            $this->table(
                ['Criterio', 'Importe'],
                collect($set)->map(fn ($f) => [
                    $f['label'],
                    '$' . $this->money($f['value']),
                ])->all()
            );

            foreach ($set as $f) {
                $this->line('    · ' . $f['label'] . ': ' . wordwrap($f['note'], 90, "\n      "));
            }
            $this->line('');
        }

        $this->mostrarPuentes($meses, $acum);

        return Command::SUCCESS;
    }

    protected function mostrarPuentes(array $meses, array $acum): void
    {
        $puentes = count($meses) > 1
            ? $this->statementPuentesAcumulados($acum)
            : $meses[0]['puentes'];

        $this->line('  <options=bold>DIFERENCIAS DE CRITERIO, CON SU PRECIO</>');
        $this->line('  Cada fila es una razón legítima por la que dos personas honestas obtienen');
        $this->line('  cifras distintas del mismo mes. Busca aquí el número que reclama el cliente.');
        $this->line('');

        if (!$puentes) {
            $this->info('  No hay ninguna diferencia de criterio: todos los criterios dan lo mismo.');
            $this->line('  Si el cliente ve un desfase, NO viene de aquí → php artisan billing:audit-books --tenant=…');
            $this->line('');
            return;
        }

        $target    = $this->option('target') !== null ? (float) $this->option('target') : null;
        $tolerance = (float) $this->option('tolerance');
        $explican  = [];

        $filas = [];
        foreach ($puentes as $p) {
            $coincide = $target !== null && abs(abs($p['delta']) - abs($target)) <= $tolerance;

            if ($coincide) {
                $explican[] = $p;
            }

            $filas[] = [
                $coincide ? '<fg=green;options=bold>►</>' : '',
                $p['grupo'],
                $p['contra'],
                '$' . $this->money($p['delta']),
            ];
        }

        $this->table(['', 'Grupo', 'Diferencia entre', 'Vale'], $filas);

        foreach ($puentes as $p) {
            $this->line('    · $' . $this->money($p['delta']) . ' — ' . wordwrap($p['porque'], 90, "\n      "));
        }

        $this->line('');

        if ($target === null) {
            $this->line('  Pasa --target=<importe> para que se marque la que explique el desfase reclamado.');
            $this->line('');
            return;
        }

        if ($explican) {
            $this->info('  ✓ El desfase de $' . $this->money($target) . ' coincide con '
                . count($explican) . ' diferencia(s) de criterio:');

            foreach ($explican as $p) {
                $this->line('      ► ' . $p['contra'] . '  =  $' . $this->money($p['delta']));
                $this->line('        ' . wordwrap($p['porque'], 90, "\n        "));
            }

            $this->line('');
            $this->line('  Lectura: muy probablemente NO hay nada roto — las dos cifras miden cosas');
            $this->line('  distintas. Confírmalo con el cliente preguntando qué incluye su planilla,');
            $this->line('  y corre igualmente billing:audit-books para descartar un descuadre real.');
        } else {
            $this->warn('  ✗ Ninguna diferencia de criterio explica $' . $this->money($target)
                . ' (margen ±$' . $this->money($tolerance) . ').');
            $this->line('');
            $this->line('  Lectura: el desfase NO se explica por cómo se cuenta. Siguiente paso:');
            $this->line('      php artisan billing:audit-books --tenant=' . $this->option('tenant'));
            $this->line('  Y si ahí tampoco sale nada, pide la planilla del cliente: puede tener');
            $this->line('  cobros que nunca entraron al sistema (recaudos por fuera, o meses previos');
            $this->line('  a la puesta en marcha).');
        }

        $this->line('');
    }

    /** Suma los flujos de todos los meses, criterio por criterio. */
    protected function acumular(array $meses): array
    {
        $acum = ['facturado' => [], 'recaudado' => []];

        foreach (['facturado', 'recaudado'] as $grupo) {
            foreach ($meses as $mes) {
                foreach ($mes[$grupo] as $clave => $f) {
                    if (!isset($acum[$grupo][$clave])) {
                        $acum[$grupo][$clave] = $f;
                        continue;
                    }
                    $acum[$grupo][$clave]['value'] = round($acum[$grupo][$clave]['value'] + $f['value'], 2);
                }
            }
        }

        return $acum;
    }

    /**
     * Los puentes del acumulado. Se recalculan sobre los totales y no se suman
     * los de cada mes: sumar diferencias mes a mes arrastraría el redondeo y,
     * peor, escondería que el desfase que busca el cliente es de todo el año.
     */
    protected function statementPuentesAcumulados(array $acum): array
    {
        $svc = new class extends BooksStatementService {
            public function puentesPublicos(array $f, array $r): array
            {
                return $this->puentes($f, $r);
            }
        };

        return $svc->puentesPublicos($acum['facturado'], $acum['recaudado']);
    }

    protected function money(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
