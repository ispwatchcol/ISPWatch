<?php

namespace Tests\Feature\Console;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un candado de withoutOverlapping que sobrevive a su proceso no puede costar
 * más de un tick de su tarea.
 *
 * Laravel deja el candado 24 horas por defecto y sólo lo suelta cuando la tarea
 * termina. En producción el planificador corre dentro de un worker que se
 * recicla cada hora: si lo mata a mitad de una corrida, el candado se queda en
 * la base y la tarea no vuelve a correr hasta el día siguiente. Así salió la
 * facturación de octubre de Chaguaní un día tarde: 82 facturas a las 09:00 del
 * día 1, nada en todo el día, y las 677 restantes a las 09:00 del día 2
 * (bitácora § 89).
 */
class ScheduledTaskLockExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_overlap_lock_expires_before_the_next_tick_of_its_task(): void
    {
        $guarded = array_filter($this->scheduledEvents(), fn (Event $e) => $e->withoutOverlapping);

        $this->assertNotEmpty($guarded, 'Ninguna tarea usa withoutOverlapping: la prueba no estaría mirando nada.');

        foreach ($guarded as $event) {
            $tick = $this->shortestIntervalInMinutes($event->expression);

            $this->assertLessThan(
                $tick,
                $event->expiresAt,
                "«{$this->taskName($event)}» corre cada {$tick} min y su candado dura {$event->expiresAt} min: "
                . 'una corrida muerta la dejaría sin correr más de un tick. Pásale a withoutOverlapping() '
                . 'un vencimiento menor que el intervalo.'
            );
        }
    }

    public function test_a_monthly_billing_run_killed_mid_way_does_not_block_the_next_hour(): void
    {
        // El candado vive en la base en producción (CACHE_STORE=database).
        config(['cache.default' => 'database']);

        $event = $this->eventFor('billing:generate-monthly');
        $this->assertTrue($event->withoutOverlapping);

        // 09:00 — la corrida toma el candado y el proceso muere sin soltarlo:
        // lo que queda es la fila de cache_locks, con su vencimiento.
        $this->travelTo(Carbon::parse('2026-10-01 09:00:01'));
        $this->assertTrue($event->mutex->create($event));

        // Se lee la fila en vez de volver a pedir el candado. Pedirlo con la
        // fila presente hace un INSERT que choca, y en PostgreSQL eso aborta la
        // transacción con la que RefreshDatabase envuelve la prueba. En
        // producción no hay transacción envolvente y Laravel toma el candado
        // vencido con un UPDATE: así arrancó la corrida del 2-oct a las 09:00.
        $store = Cache::store('database')->getStore();
        $vence = (int) DB::table('cache_locks')
            ->where('key', $store->getPrefix() . $event->mutexName())
            ->value('expiration');

        $this->assertGreaterThan(now()->getTimestamp(), $vence, 'Mientras la corrida está viva, el candado tiene que impedir que otra se solape.');

        // 10:00 — el siguiente tick tiene que encontrar el candado vencido.
        $this->assertLessThanOrEqual(
            Carbon::parse('2026-10-01 10:00:00')->getTimestamp(),
            $vence,
            'El candado de la corrida de las 09:00 que murió vence a las '
            . Carbon::createFromTimestamp($vence)->format('Y-m-d H:i')
            . ': la corrida de las 10:00 se saltaría y la facturación esperaría.'
        );
    }

    /** @return array<int, Event> */
    private function scheduledEvents(): array
    {
        return $this->app->make(Schedule::class)->events();
    }

    private function eventFor(string $command): Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_ends_with((string) $event->command, $command)) {
                return $event;
            }
        }

        $this->fail("La tarea {$command} no está agendada.");
    }

    /** El menor hueco entre ejecuciones consecutivas (un cron como «0,45» no es regular). */
    private function shortestIntervalInMinutes(string $expression): int
    {
        $cron     = new CronExpression($expression);
        $previous = Carbon::instance($cron->getNextRunDate('2026-10-01 00:00:00'));
        $shortest = PHP_INT_MAX;

        for ($i = 0; $i < 48; $i++) {
            $next     = Carbon::instance($cron->getNextRunDate($previous));
            $shortest = min($shortest, (int) $previous->diffInMinutes($next));
            $previous = $next;
        }

        return $shortest;
    }

    private function taskName(Event $event): string
    {
        return trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command));
    }
}
