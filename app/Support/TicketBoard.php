<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * La lista de tickets como COLA DE TRABAJO (bitácora § 133).
 *
 * `GET /support` devolvía todos los tickets del ISP de una vez —abiertos y
 * cerrados mezclados, cada uno con sus notas y adjuntos— y la pantalla filtraba
 * en el navegador. Funcionaba con pocos tickets y se iba a poner lento con
 * muchos; y sobre todo no decía qué había que hacer.
 *
 * Esto responde la misma pregunta con otra forma: una PESTAÑA (abiertos, míos,
 * sin asignar, en espera, para cerrar, cerrados), una página, y cuántos hay en
 * cada pestaña. Se activa con `?page=` o `?tab=`; sin ellos `GET /support` sigue
 * devolviendo el arreglo de siempre, porque la ficha del cliente y los
 * integradores lo consumen así.
 *
 * Los grupos de estado salen de `TicketWorkflow::GRUPOS`: la cola no tiene su
 * propia idea de qué estado es «en espera».
 */
class TicketBoard
{
    public const TABS = [
        'open',        // todo lo que no está cerrado
        'mine',        // abiertos asignados a quien mira
        'unassigned',  // abiertos sin técnico
        'new',         // grupo «nuevo»
        'in_progress', // grupo «en curso»
        'waiting',     // grupo «en espera»
        'to_close',    // grupo «para cerrar»
        'closed',      // grupo «cerrado»
        'all',
    ];

    private const GRUPO_DE_TAB = [
        'new'         => TicketWorkflow::GRUPO_NUEVO,
        'in_progress' => TicketWorkflow::GRUPO_EN_CURSO,
        'waiting'     => TicketWorkflow::GRUPO_EN_ESPERA,
        'to_close'    => TicketWorkflow::GRUPO_PARA_CERRAR,
        'closed'      => TicketWorkflow::GRUPO_CERRADO,
    ];

    public function __construct(private readonly TicketCatalogs $catalogs)
    {
    }

    /** @return array<string, mixed> */
    public function listar(Request $request): array
    {
        /** @var User|null $usuario */
        $usuario  = $request->user();
        $tenantId = $usuario?->tenant_id;
        $yo       = (int) ($usuario?->id ?? 0);
        $modo     = TicketWorkflow::modoDelTenant($tenantId);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'open';
        $porPagina = min(max((int) $request->query('per_page', 25), 5), 100);

        $base = SupportTicket::query();

        // Igual que el listado de siempre: además del scope de BelongsToTenant,
        // el filtro explícito por el tenant de quien pide.
        if ($tenantId) {
            $base->where('tenant_id', $tenantId);
        }

        $this->aplicarFiltros($base, $request);

        $contadores = $this->contar(clone $base, $yo);

        $consulta = clone $base;
        $this->aplicarPestana($consulta, $tab, $yo);
        $this->ordenar($consulta, $tab, (string) $request->query('sort', 'urgency'));

        $pagina = $consulta
            ->with([
                'user:id,user_name,user_lastname,tel',
                'user.customerProfile:user_id,address,city,service_status',
                'staff:id,user_name,user_lastname',
            ])
            ->paginate($porPagina);

        return [
            'data'          => collect($pagina->items())->map(fn (SupportTicket $t) => $this->presentar($t, $modo))->values(),
            'current_page'  => $pagina->currentPage(),
            'last_page'     => $pagina->lastPage(),
            'per_page'      => $pagina->perPage(),
            'total'         => $pagina->total(),
            'tab'           => $tab,
            'counts'        => $contadores,
            'workflow_mode' => $modo,
        ];
    }

    // ── Filtros comunes a todas las pestañas ────────────────────────────

    private function aplicarFiltros(Builder $query, Request $request): void
    {
        if ($request->filled('priority') && $request->query('priority') !== 'all') {
            $query->where('priority_id', $this->catalogs->id(TicketCatalogs::PRIORITY, (string) $request->query('priority')));
        }

        $tecnico = $request->query('staff_id');

        if ($tecnico === 'unassigned') {
            $query->whereNull('staff_id');
        } elseif (is_numeric($tecnico)) {
            $query->where('staff_id', (int) $tecnico);
        }

        $asunto = $request->query('subject_id');

        if ($asunto === 'other') {
            $query->whereNull('subject_id');
        } elseif (is_numeric($asunto)) {
            $query->where('subject_id', (int) $asunto);
        }

        if ($request->filled('search')) {
            $this->buscar($query, trim((string) $request->query('search')));
        }
    }

    /**
     * Búsqueda: «#123» es el número del ticket; una cifra suelta puede ser el
     * número, el teléfono o la cédula; el resto busca en asunto, descripción,
     * nombre, teléfono y dirección del cliente. `whereLike` no distingue
     * mayúsculas en ningún motor (ilike en PostgreSQL).
     */
    private function buscar(Builder $query, string $texto): void
    {
        if ($texto === '') {
            return;
        }

        if (preg_match('/^#\s*(\d+)$/', $texto, $m)) {
            $query->where('id', (int) $m[1]);

            return;
        }

        $soloDigitos = preg_match('/^\d+$/', $texto) === 1;

        $query->where(function (Builder $q) use ($texto, $soloDigitos) {
            if ($soloDigitos) {
                $q->where('id', (int) $texto);
            }

            $q->orWhereLike('subject', $texto)
              ->orWhereLike('description', $texto)
              ->orWhereHas('user', function (Builder $u) use ($texto) {
                  $u->where(function (Builder $c) use ($texto) {
                      $c->whereLike('user_name', $texto)
                        ->orWhereLike('user_lastname', $texto)
                        ->orWhereLike('tel', $texto);

                      // «Juan Pérez»: nombre y apellido en columnas distintas.
                      $partes = preg_split('/\s+/', $texto) ?: [];

                      if (count($partes) >= 2) {
                          $c->orWhere(function (Builder $n) use ($partes) {
                              $n->whereLike('user_name', $partes[0])
                                ->whereLike('user_lastname', $partes[count($partes) - 1]);
                          });
                      }
                  });
              })
              ->orWhereHas('user.customerProfile', function (Builder $p) use ($texto) {
                  $p->where(function (Builder $c) use ($texto) {
                      $c->whereLike('address', $texto)
                        ->orWhereLike('cedula', $texto)
                        ->orWhereLike('ip_user', $texto);
                  });
              });
        });
    }

    // ── Pestañas ─────────────────────────────────────────────────────────

    private function aplicarPestana(Builder $query, string $tab, int $yo): void
    {
        $cerrados = $this->idsDelGrupo(TicketWorkflow::GRUPO_CERRADO);

        match ($tab) {
            'open'       => $query->whereNotIn('status_id', $cerrados),
            'mine'       => $query->whereNotIn('status_id', $cerrados)->where('staff_id', $yo),
            'unassigned' => $query->whereNotIn('status_id', $cerrados)->whereNull('staff_id'),
            'all'        => null,
            default      => $query->whereIn('status_id', $this->idsDelGrupo(self::GRUPO_DE_TAB[$tab])),
        };
    }

    /** @return array<int, int> */
    private function idsDelGrupo(string $grupo): array
    {
        $ids = $this->catalogs->ids(TicketCatalogs::STATUS, TicketWorkflow::codigosDelGrupo($grupo));

        // `whereIn([])` no devuelve nada y `whereNotIn([])` lo devuelve todo,
        // que es justo lo correcto para un grupo sin filas en el catálogo.
        return $ids;
    }

    /**
     * Cuántos hay en cada pestaña, en UNA consulta. Nueve `count()` serían
     * nueve viajes a la base —con Supabase, más de dos segundos— para pintar
     * unos números al lado de las pestañas.
     *
     * El id de quien mira va escrito en la expresión (es un entero, viene de
     * la sesión) y no como parámetro: PostgreSQL no reconoce como iguales dos
     * expresiones con parámetros distintos, y el GROUP BY fallaría.
     *
     * @return array<string, int>
     */
    private function contar(Builder $query, int $yo): array
    {
        $quien = "CASE WHEN staff_id IS NULL THEN 0 WHEN staff_id = {$yo} THEN 1 ELSE 2 END";

        $filas = $query
            ->toBase()
            ->selectRaw("status_id, {$quien} as quien, COUNT(*) as total")
            ->groupBy('status_id')
            ->groupByRaw($quien)
            ->get();

        $contadores = array_fill_keys(self::TABS, 0);

        foreach ($filas as $fila) {
            $total  = (int) $fila->total;
            $codigo = $this->catalogs->code(TicketCatalogs::STATUS, $fila->status_id !== null ? (int) $fila->status_id : null);
            $grupo  = TicketWorkflow::grupoDe($codigo);

            $contadores['all'] += $total;

            foreach (self::GRUPO_DE_TAB as $tab => $grupoDeTab) {
                if ($grupo === $grupoDeTab) {
                    $contadores[$tab] += $total;
                }
            }

            if ($grupo === TicketWorkflow::GRUPO_CERRADO) {
                continue;
            }

            $contadores['open'] += $total;

            if ((int) $fila->quien === 1) {
                $contadores['mine'] += $total;
            } elseif ((int) $fila->quien === 0) {
                $contadores['unassigned'] += $total;
            }
        }

        return $contadores;
    }

    // ── Orden ────────────────────────────────────────────────────────────

    /**
     * Cerrados: el último cerrado arriba. El resto, por defecto, como una cola:
     * lo más urgente primero y, a igual urgencia, lo que lleva más tiempo
     * esperando. `sort=recent` deja el orden de siempre (lo más nuevo arriba).
     */
    private function ordenar(Builder $query, string $tab, string $orden): void
    {
        if ($tab === 'closed') {
            $query->orderByDesc('closed_at')->orderByDesc('id');

            return;
        }

        if ($orden === 'recent') {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }

        $peso = [];

        foreach (['urgent' => 4, 'high' => 3, 'medium' => 2, 'low' => 1] as $codigo => $valor) {
            $id = $this->catalogs->id(TicketCatalogs::PRIORITY, $codigo);

            if ($id !== null) {
                $peso[] = 'WHEN ' . (int) $id . ' THEN ' . $valor;
            }
        }

        if ($peso) {
            $query->orderByRaw('CASE priority_id ' . implode(' ', $peso) . ' ELSE 0 END DESC');
        }

        $query->orderBy('created_at')->orderBy('id');
    }

    // ── Fila ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function presentar(SupportTicket $t, string $modo): array
    {
        $estado = $t->status;
        $perfil = $t->user?->customerProfile;

        return [
            'id'               => $t->id,
            'subject'          => $t->subject,
            'subject_id'       => $t->subject_id,
            'status'           => $estado,
            'status_label'     => (TicketWorkflow::esSimple($modo) ? TicketWorkflow::etiquetaSimple($estado) : null)
                ?? $t->status_label ?? $estado,
            'status_group'     => TicketWorkflow::grupoDe($estado),
            'priority'         => $t->priority,
            'priority_label'   => $t->priority_label,
            'staff_id'         => $t->staff_id,
            'staff'            => $t->staff ? [
                'id'            => $t->staff->id,
                'user_name'     => $t->staff->user_name,
                'user_lastname' => $t->staff->user_lastname,
            ] : null,
            'user_id'          => $t->user_id,
            'user'             => $t->user ? [
                'id'            => $t->user->id,
                'user_name'     => $t->user->user_name,
                'user_lastname' => $t->user->user_lastname,
                'tel'           => $t->user->tel,
            ] : null,
            // Lo que el técnico necesita para salir: dónde es.
            'customer_address' => $perfil?->address,
            'customer_city'    => $perfil?->city,
            'service_status'   => $perfil?->service_status,
            'no_charge'        => (bool) $t->no_charge,
            'no_charge_reason' => $t->no_charge_reason,
            'created_at'       => $t->created_at?->toJSON(),
            'updated_at'       => $t->updated_at?->toJSON(),
            'closed_at'        => $t->closed_at?->toJSON(),
        ];
    }
}
