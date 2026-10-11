<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketSubject;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de asuntos frecuentes del ticket (por ISP).
 *
 * Leer: `ticket_view` — quien radica o filtra tickets necesita la lista.
 * Escribir: `ticket_manage_catalogs` — D-13, delegada en el equipo; se
 * concedió a los roles `admin` por migración.
 *
 * El tenant lo pone el scope de `BelongsToTenant`: un asunto de otro ISP no se
 * puede ni leer ni tocar desde aquí (404, no 403, para no confirmar que existe).
 */
class TicketSubjectController extends Controller
{
    /**
     * Todos los asuntos del ISP, retirados incluidos, con cuántos tickets los
     * usan. `?active=1` devuelve sólo los que se pueden elegir.
     */
    public function index(Request $request)
    {
        $query = TicketSubject::query()->ordered();

        if ($request->boolean('active')) {
            $query->active();
        }

        // Los archivados también cuentan: siguen siendo tickets del ISP, y el
        // conteo decide si un asunto se puede borrar.
        $usos = SupportTicket::withTrashed()
            ->whereNotNull('subject_id')
            ->selectRaw('subject_id, COUNT(*) as total')
            ->groupBy('subject_id')
            ->pluck('total', 'subject_id');

        return response()->json(
            $query->get()->map(fn (TicketSubject $s) => $this->presentar($s, (int) ($usos[$s->id] ?? 0)))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:120',
            'sort_order' => 'nullable|integer|min:0|max:65000',
            'is_active'  => 'nullable|boolean',
        ]);

        $nombre = $this->normalizar($data['name']);
        $this->exigirNombreLibre($nombre);

        $subject = TicketSubject::create([
            'tenant_id'  => $request->user()->tenant_id,
            'name'       => $nombre,
            // Sin orden explícito va al final: quien agrega un asunto nuevo
            // espera verlo debajo de los que ya ordenó, no arriba.
            'sort_order' => $data['sort_order'] ?? ((int) TicketSubject::max('sort_order') + 10),
            'is_active'  => $data['is_active'] ?? true,
        ]);

        return response()->json($this->presentar($subject, 0), 201);
    }

    public function update(Request $request, $id)
    {
        $subject = TicketSubject::findOrFail($id);

        $data = $request->validate([
            'name'       => 'sometimes|required|string|max:120',
            'sort_order' => 'sometimes|integer|min:0|max:65000',
            'is_active'  => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->normalizar($data['name']);
            $this->exigirNombreLibre($data['name'], $subject->id);
        }

        // Renombrar NO reescribe el texto de los tickets que ya lo usan: el
        // expediente dice lo que se radicó ese día. Siguen agrupados porque el
        // filtro va por `subject_id`.
        $subject->update($data);

        return response()->json($this->presentar($subject->fresh(), $this->usos($subject->id)));
    }

    /**
     * Sólo se borra un asunto que nunca usó ningún ticket. El que ya se usó se
     * RETIRA: borrarlo dejaría esos tickets sin agrupar en el filtro, que es
     * exactamente lo que este catálogo vino a resolver.
     */
    public function destroy($id)
    {
        $subject = TicketSubject::findOrFail($id);
        $usos = $this->usos($subject->id);

        if ($usos > 0) {
            return response()->json([
                'message' => "No se puede eliminar «{$subject->name}»: lo usan {$usos} ticket(s). "
                    . 'Desactívalo para que deje de ofrecerse; los tickets viejos seguirán agrupados bajo él.',
                'error'   => 'subject_in_use',
                'usage'   => $usos,
            ], 409);
        }

        $subject->delete();

        return response()->json(['message' => 'Asunto eliminado.']);
    }

    private function usos(int $subjectId): int
    {
        return SupportTicket::withTrashed()->where('subject_id', $subjectId)->count();
    }

    /** Espacios de más fuera; el resto como lo escribió quien administra. */
    private function normalizar(string $nombre): string
    {
        return trim(preg_replace('/\s+/u', ' ', $nombre));
    }

    /**
     * «Sin internet» y «SIN INTERNET» son el mismo asunto: dejar pasar los dos
     * reproduciría dentro del catálogo el desorden que el catálogo vino a
     * quitar. El índice único de la tabla distingue mayúsculas; esta
     * comprobación no.
     */
    private function exigirNombreLibre(string $nombre, ?int $exceptoId = null): void
    {
        if ($nombre === '') {
            throw ValidationException::withMessages(['name' => 'El nombre del asunto es obligatorio.']);
        }

        $existe = TicketSubject::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nombre)])
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'name' => "Ya existe un asunto llamado «{$nombre}».",
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function presentar(TicketSubject $s, int $usos): array
    {
        return [
            'id'         => $s->id,
            'name'       => $s->name,
            'sort_order' => $s->sort_order,
            'is_active'  => $s->is_active,
            'usage'      => $usos,
        ];
    }
}
