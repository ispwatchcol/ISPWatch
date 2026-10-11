<?php

namespace App\Observers;

use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\User;
use App\Support\TicketCatalogs;

/**
 * Escribe el historial inalterable del ticket. PR #3 · F1-17.
 *
 * POR QUÉ OBSERVER Y NO INSTRUMENTAR EL CONTROLADOR
 *
 * Por lo mismo que documentan `MoneyAuditObserver` y `PartnerEventObserver`: un
 * ticket se mueve por más de una puerta. Hoy son `update()` y `updateStatus()`
 * del controlador; mañana puede ser un comando o un job. Instrumentar cada
 * puerta significa acordarse cada vez, y el requerimiento no admite huecos:
 * «los cambios de estado, asignaciones, diagnósticos y cierres deben conservar
 * auditoría».
 *
 * Y sobre todo: el observer ve el CAMBIO REAL, no el payload. Si la petición
 * manda `status: open` sobre un ticket que ya estaba abierto, `getChanges()`
 * viene vacío y no se registra nada. Instrumentando el controlador habría que
 * comparar a mano, y un `PUT` que reenvía el formulario entero —que es justo lo
 * que hace la pantalla de edición— dejaría un evento por campo en cada guardado,
 * volviendo el historial inútil de puro ruido.
 *
 * LÍMITE CONOCIDO, EL MISMO QUE LOS OTROS OBSERVERS
 *
 * `SupportTicket::where(...)->update([...])` no pasa por Eloquent y no dispara
 * nada. Hoy ningún camino hace eso con tickets —se auditó al preparar la R3—,
 * pero si se agrega uno habrá que registrar el evento a mano.
 */
class SupportTicketHistoryObserver
{
    /**
     * Columna real => [tipo de evento, campo público, catálogo].
     *
     * Se observan las COLUMNAS (`status_id`), no los atributos públicos
     * (`status`): `getChanges()` habla de columnas, y los accessors de la R2/R3
     * no aparecen ahí. Observar el atributo público no detectaría nada.
     */
    private const CAMPOS = [
        'status_id'          => [SupportTicketHistory::STATUS,          'status',          TicketCatalogs::STATUS],
        'priority_id'        => [SupportTicketHistory::PRIORITY,        'priority',        TicketCatalogs::PRIORITY],
        'category_id'        => [SupportTicketHistory::CATEGORY,        'category',        TicketCatalogs::CATEGORY],
        'symptom_id'         => [SupportTicketHistory::SYMPTOM,         'symptom',         TicketCatalogs::SYMPTOM],
        'suspected_cause_id' => [SupportTicketHistory::SUSPECTED_CAUSE, 'suspected_cause', TicketCatalogs::CAUSE],
        'confirmed_cause_id' => [SupportTicketHistory::CONFIRMED_CAUSE, 'confirmed_cause', TicketCatalogs::CAUSE],
        'solution_id'        => [SupportTicketHistory::SOLUTION,        'solution',        TicketCatalogs::SOLUTION],
        'result_id'          => [SupportTicketHistory::RESULT,          'result',          TicketCatalogs::RESULT],
    ];

    /** Los tres que no son diagnóstico, para no repetirlos en `metadata`. */
    private const NO_DIAGNOSTICO = ['status', 'priority', 'category'];

    public function created(SupportTicket $ticket): void
    {
        // El alta es UN evento, no nueve. Los valores iniciales viajan en
        // `metadata`: registrar cada campo por separado en la creación llenaría
        // el historial de «(vacío) → abierto» sin aportar nada, y de un ticket
        // recién creado lo que importa es que se creó y con qué.
        SupportTicketHistory::registrar(
            $ticket,
            SupportTicketHistory::CREATED,
            metadata: array_filter([
                'subject'     => $ticket->subject,
                'status'      => $ticket->status,
                'priority'    => $ticket->priority,
                'category'    => $ticket->category,
                'diagnostico' => $this->diagnosticoInicial($ticket),
                'tecnico'     => $this->nombreDe($ticket->staff_id),
            ], fn ($v) => $v !== null && $v !== []),
        );
    }

    public function updated(SupportTicket $ticket): void
    {
        $cambios = $ticket->getChanges();

        foreach (self::CAMPOS as $columna => [$evento, $campo, $tabla]) {
            if (!array_key_exists($columna, $cambios)) {
                continue;
            }

            $anterior = $ticket->getOriginal($columna);
            $nuevo    = $cambios[$columna];

            if ($this->mismoId($anterior, $nuevo)) {
                continue;
            }

            SupportTicketHistory::registrar(
                $ticket,
                $evento,
                field: $campo,
                oldValue: $this->codigo($tabla, $anterior),
                newValue: $this->codigo($tabla, $nuevo),
                metadata: array_filter([
                    'old_label' => $this->etiqueta($tabla, $anterior),
                    'new_label' => $this->etiqueta($tabla, $nuevo),
                ], fn ($v) => $v !== null),
            );
        }

        if (array_key_exists('staff_id', $cambios)) {
            $this->registrarTecnico($ticket, $cambios['staff_id']);
        }

        if (array_key_exists('subject', $cambios) || array_key_exists('subject_id', $cambios)) {
            $this->registrarAsunto($ticket);
        }
    }

    /**
     * Cambio de asunto. Un solo evento aunque cambien el texto y el
     * `subject_id` a la vez: para quien lee el historial es una sola decisión
     * («se reclasificó de Sin internet a Daño en fibra»).
     *
     * El valor es el TEXTO, no el id: es lo que se vio en pantalla ese día, y
     * un asunto renombrado o borrado después no debe cambiar lo que dice el
     * historial.
     */
    private function registrarAsunto(SupportTicket $ticket): void
    {
        $anterior = $ticket->getOriginal('subject');
        $nuevo    = $ticket->subject;

        $idAntes = $ticket->getOriginal('subject_id');
        $idAhora = $ticket->subject_id;

        if ((string) $anterior === (string) $nuevo && $this->mismoId($idAntes, $idAhora)) {
            return;
        }

        SupportTicketHistory::registrar(
            $ticket,
            SupportTicketHistory::SUBJECT,
            field: 'subject',
            oldValue: $anterior,
            newValue: $nuevo,
            metadata: [
                // Si el asunto quedó del catálogo o escrito a mano («Otro…»).
                'from_catalog' => $idAhora !== null,
            ],
        );
    }

    /** Reasignación de técnico. El valor es el id; el nombre va en metadata. */
    private function registrarTecnico(SupportTicket $ticket, $nuevo): void
    {
        $anterior = $ticket->getOriginal('staff_id');

        if ($this->mismoId($anterior, $nuevo)) {
            return;
        }

        SupportTicketHistory::registrar(
            $ticket,
            SupportTicketHistory::STAFF,
            field: 'staff_id',
            // Aquí va el id y no un «código»: la identidad de una persona ES su
            // id. El nombre puede cambiar y no identifica de forma estable.
            oldValue: $anterior === null ? null : (string) $anterior,
            newValue: $nuevo === null ? null : (string) $nuevo,
            metadata: array_filter([
                'old_label' => $this->nombreDe($anterior),
                'new_label' => $this->nombreDe($nuevo),
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * Dos referencias al mismo registro.
     *
     * `getChanges()` compara con el original, pero un id que pasa de "3"
     * —cadena, como lo devuelve PDO en PostgreSQL— a 3 entero cuenta como
     * cambio para Eloquent y no lo es para nadie más. Sin esto, cada guardado
     * sobre PostgreSQL podía dejar eventos fantasma que SQLite no reproduce.
     */
    private function mismoId($a, $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return (int) $a === (int) $b;
    }

    /**
     * Diagnóstico con el que nace el ticket, si lo trae.
     *
     * @return array<string, string>
     */
    private function diagnosticoInicial(SupportTicket $ticket): array
    {
        $salida = [];

        foreach (self::CAMPOS as $columna => [, $campo, $tabla]) {
            if (in_array($campo, self::NO_DIAGNOSTICO, true)) {
                continue;
            }

            $codigo = $this->codigo($tabla, $ticket->getAttributes()[$columna] ?? null);

            if ($codigo !== null) {
                $salida[$campo] = $codigo;
            }
        }

        return $salida;
    }

    private function codigo(string $tabla, $id): ?string
    {
        return $id === null ? null : app(TicketCatalogs::class)->code($tabla, (int) $id);
    }

    private function etiqueta(string $tabla, $id): ?string
    {
        return $id === null ? null : app(TicketCatalogs::class)->label($tabla, (int) $id);
    }

    /**
     * Nombre legible del técnico EN EL MOMENTO del cambio.
     *
     * Va a `metadata` y no se resuelve al leer: el historial es inalterable, así
     * que tiene que poder leerse aunque el usuario se dé de baja después.
     * Resolviéndolo al leer, un empleado eliminado dejaría media bitácora
     * diciendo «—».
     */
    private function nombreDe($userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $usuario = User::withoutGlobalScopes()->find($userId);

        if (!$usuario) {
            return null;
        }

        $nombre = trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? ''));

        return $nombre !== '' ? $nombre : $usuario->email;
    }
}
