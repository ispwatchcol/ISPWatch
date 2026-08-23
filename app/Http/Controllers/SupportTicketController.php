<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use App\Services\BillingService;
use App\Support\TicketCatalogs;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use App\Mail\SendTicketNotification;

class SupportTicketController extends Controller
{
    /**
     * FASE 1 · R2 — campo de la petición => [columna FK, tabla de catálogo].
     *
     * El controlador ya no toca las columnas enum: recibe códigos, los resuelve
     * contra el catálogo y consulta por clave foránea.
     */
    private const CATALOGOS_FILTRABLES = [
        'status'   => ['status_id',   TicketCatalogs::STATUS],
        'priority' => ['priority_id', TicketCatalogs::PRIORITY],
        'category' => ['category_id', TicketCatalogs::CATEGORY],
    ];

    public function __construct(private readonly TicketCatalogs $catalogs)
    {
    }

    /**
     * Reglas de validación tomadas del catálogo y no escritas a mano.
     *
     * Con esto, retirar una fila (ponerle `valid_until`) deja de aceptarla en la
     * API sin tocar código ni desplegar. Antes la lista vivía duplicada en tres
     * sitios de este mismo archivo, y añadir un estado obligaba a acordarse de
     * los tres.
     */
    private function reglaDe(string $tabla): In
    {
        return Rule::in($this->catalogs->codigosVigentes($tabla));
    }

    /**
     * PR #2 · campo de la petición => catálogo que lo valida.
     *
     * Que cada campo se valide contra SU catálogo es lo que impide asignar una
     * causa donde va un síntoma: `Rule::in` sólo acepta los códigos de la tabla
     * que le corresponde, así que un `RF` enviado como `symptom` se rechaza con
     * un 422 y nunca llega al modelo.
     */
    private const CATALOGOS_DIAGNOSTICO = [
        'symptom'         => TicketCatalogs::SYMPTOM,
        'suspected_cause' => TicketCatalogs::CAUSE,
        'confirmed_cause' => TicketCatalogs::CAUSE,
        'solution'        => TicketCatalogs::SOLUTION,
        'result'          => TicketCatalogs::RESULT,
    ];

    /**
     * Reglas del diagnóstico, acotadas al vocabulario visible para este ISP.
     *
     * Se usa `codigosVigentesParaTenant` y no `codigosVigentes` a propósito: el
     * segundo devuelve también las filas privadas de OTROS ISP, y aceptarlas
     * dejaría un ticket apuntando a vocabulario que su dueño no puede ni ver en
     * el desplegable. El aislamiento tiene que valer en la escritura, no sólo en
     * la lectura.
     *
     * `nullable` en los cinco: un ticket puede existir sin diagnóstico y se
     * diagnostica más tarde. El PR #2 no impone obligatoriedad — eso son las
     * reglas de cierre del PR #4.
     *
     * @return array<string, array<int, mixed>>
     */
    private function reglasDeDiagnostico(?int $tenantId, string $presencia = 'sometimes'): array
    {
        $reglas = [];

        foreach (self::CATALOGOS_DIAGNOSTICO as $campo => $tabla) {
            $reglas[$campo] = [
                $presencia,
                'nullable',
                'string',
                Rule::in($this->catalogs->codigosVigentesParaTenant($tabla, $tenantId)),
            ];
        }

        return $reglas;
    }

    /**
     * Sólo los campos de diagnóstico presentes en la petición.
     *
     * Se filtra por presencia y no por valor: enviar `result: null` es una orden
     * de BORRAR el resultado, y confundirla con «no lo mandó» haría imposible
     * deshacer un diagnóstico desde el formulario.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function diagnosticoDe(array $data): array
    {
        return array_intersect_key($data, self::CATALOGOS_DIAGNOSTICO);
    }

    /**
     * Mensajes en español para los cinco campos.
     *
     * El mensaje por defecto de `in` («The selected symptom is invalid») no dice
     * nada útil a quien diligencia, y la pantalla es de personal técnico.
     *
     * @return array<string, string>
     */
    private function mensajesDeDiagnostico(): array
    {
        $nombres = [
            'symptom'         => 'El síntoma',
            'suspected_cause' => 'La causa sospechada',
            'confirmed_cause' => 'La causa confirmada',
            'solution'        => 'La acción',
            'result'          => 'El resultado',
        ];

        $mensajes = [];

        foreach ($nombres as $campo => $nombre) {
            $mensajes["{$campo}.in"] = "{$nombre} no pertenece al catálogo vigente.";
        }

        return $mensajes;
    }

    /**
     * Display a listing of support tickets.
     */
    public function index(Request $request)
    {
        // SECURITY FIX (OWASP A01): Scope to authenticated user's tenant
        $tenantId = $request->user()?->tenant_id;
        $query = SupportTicket::with(['user', 'staff', 'messages', 'attachments']);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Filtros. Llegan como CÓDIGO, como siempre, pero se resuelven contra el
        // catálogo y se filtra por clave foránea (R2). Un código inexistente
        // resuelve a null y no devuelve tickets, en vez de ignorarse en silencio.
        foreach (self::CATALOGOS_FILTRABLES as $campo => [$columna, $tabla]) {
            if ($request->has($campo) && $request->{$campo} != 'all') {
                $query->where($columna, $this->catalogs->id($tabla, $request->{$campo}));
            }
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Búsqueda. whereLike/orWhereLike eligen ilike o like según el motor y
        // escapan los comodines: con `LIKE` a secas, PostgreSQL distingue
        // mayúsculas y buscar "eliud" no encontraba "Eliud" — un fallo que los
        // tests no ven porque corren sobre SQLite, donde LIKE es insensible.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('subject', $search)
                  ->orWhereLike('description', $search);
            });
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tickets);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => ['nullable', $this->reglaDe(TicketCatalogs::CATEGORY)],
            'user_id' => 'required|exists:users,id',
            'staff_id' => 'nullable|exists:users,id',
            'sectorial_id' => 'nullable|integer|exists:sectorial,id',
            'attachments.*' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
            // PR #2: el diagnóstico se puede capturar ya desde el alta, aunque lo
            // habitual sea rellenarlo después de la visita.
        ] + $this->reglasDeDiagnostico($tenantId), $this->mensajesDeDiagnostico());

        DB::beginTransaction();

        try {
            // Crear el ticket
            // SECURITY FIX (OWASP A01): Derive tenant_id from authenticated user
            $ticket = SupportTicket::create([
                // `tenant_id` va PRIMERO: los mutators del diagnóstico resuelven
                // el código en el ámbito del tenant, y `fill()` recorre el array
                // en orden. Ver `SupportTicket::tenantParaResolver()`.
                'tenant_id' => $request->user()?->tenant_id ?? 1,
                'user_id' => $data['user_id'],
                'staff_id' => $data['staff_id'] ?? null,
                'sectorial_id' => $data['sectorial_id'] ?? null,
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'general',
                'priority' => SupportTicket::PRIORITY_MEDIUM,
                'status' => SupportTicket::STATUS_OPEN,
            ] + $this->diagnosticoDe($data));

            if (!empty($data['sectorial_id'])) {
                \App\Models\SectorialHistory::log(
                    (int) $data['sectorial_id'],
                    'ticket_linked',
                    'Se vinculó el ticket #' . $ticket->id . ': ' . $ticket->subject,
                    ['ticket_id' => $ticket->id]
                );
            }

            // Subir archivos adjuntos si existen
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = $file->storeAs(
                        "support_attachments/{$ticket->id}",
                        $fileName,
                        'public'
                    );

                    SupportTicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $data['user_id'] ?? 1,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            // Recargar relaciones
            $ticket->load(['user', 'staff', 'attachments']);

            // Enviar email de notificación (opcional, no debe fallar la creación)
            /*
            try {
                Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'created'));
            } catch (\Exception $e) {
                \Log::error('Error sending ticket notification email: ' . $e->getMessage());
            }
            */

            return response()->json([
                'message' => 'Ticket creado correctamente. ✅',
                'ticket' => $ticket
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified ticket.
     */
    public function show($id)
    {
        $ticket = SupportTicket::with([
            'user',
            'staff',
            'messages.user',
            'attachments.user'
        ])->findOrFail($id);

        return response()->json($ticket);
    }

    /**
     * Update the specified ticket.
     */
    public function update(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $validator = \Validator::make($request->all(), [
            'subject' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'category' => ['sometimes', $this->reglaDe(TicketCatalogs::CATEGORY)],
            'priority' => ['sometimes', $this->reglaDe(TicketCatalogs::PRIORITY)],
            'status'   => ['sometimes', $this->reglaDe(TicketCatalogs::STATUS)],
            'staff_id' => 'sometimes|nullable|exists:users,id',
            'sectorial_id' => 'sometimes|nullable|integer|exists:sectorial,id',
            'attachments.*' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
            // PR #2. El tenant sale del ticket y no del usuario: `findOrFail` ya
            // pasó por el scope global de BelongsToTenant, así que el ticket es
            // forzosamente del ISP de quien pide, y usar su tenant deja el
            // vocabulario admitido alineado con el dueño del dato.
        ] + $this->reglasDeDiagnostico($ticket->tenant_id), $this->mensajesDeDiagnostico());

        if ($validator->fails()) {
            \Log::warning('Validation failed for ticket update:', [
                'id' => $id,
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->except(['attachments'])
            ]);
            return response()->json([
                'message' => 'Error de validación.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        DB::beginTransaction();

        try {
            $oldStatus = $ticket->status;

            $ticket->update($data);

            // Si cambia a resuelto, actualizar resolved_at
            if (isset($data['status']) && $data['status'] === SupportTicket::STATUS_RESOLVED && $oldStatus !== SupportTicket::STATUS_RESOLVED) {
                $ticket->resolved_at = now();
                $ticket->save();
            }

            // Subir archivos adjuntos si existen en update
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = $file->storeAs(
                        "support_attachments/{$ticket->id}",
                        $fileName,
                        'public'
                    );

                    SupportTicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => Auth::id() ?? 1,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            $ticket->load(['user', 'staff', 'messages', 'attachments']);

            // Enviar email si cambió el estado
            if (isset($data['status']) && $oldStatus !== $data['status']) {
                try {
                    Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'updated'));
                } catch (\Exception $e) {
                    \Log::error('Error sending ticket notification email: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Ticket actualizado correctamente. ✅',
                'ticket' => $ticket
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating ticket: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Error al actualizar el ticket.',
                'error' => $e->getMessage(),
                'details' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified ticket.
     */
    public function destroy($id)
    {
        $ticket = SupportTicket::findOrFail($id);

        DB::beginTransaction();

        try {
            // Eliminar archivos del storage
            foreach ($ticket->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            $ticket->delete();

            DB::commit();

            return response()->json([
                'message' => 'Ticket eliminado correctamente. ✅'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar el ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get support statistics.
     */
    public function statistics(Request $request)
    {
        // SECURITY FIX (OWASP A04): Scope all statistics to the authenticated user's tenant
        $tenantId = $request->user()?->tenant_id;
        $baseQuery = SupportTicket::query();
        if ($tenantId) {
            $baseQuery->where('tenant_id', $tenantId);
        }

        $idDeEstado = fn (string $code) => $this->catalogs->id(TicketCatalogs::STATUS, $code);

        $totalTickets = (clone $baseQuery)->count();
        $openTickets = (clone $baseQuery)->where('status_id', $idDeEstado(SupportTicket::STATUS_OPEN))->count();
        $inProgressTickets = (clone $baseQuery)->where('status_id', $idDeEstado(SupportTicket::STATUS_IN_PROGRESS))->count();

        // Tickets resueltos este mes
        $startOfMonth = now()->startOfMonth();
        $resolvedThisMonth = (clone $baseQuery)->where('status_id', $idDeEstado(SupportTicket::STATUS_RESOLVED))
            ->where('resolved_at', '>=', $startOfMonth)
            ->count();

        // Tiempo promedio de resolución (en días)
        $resolvedTickets = (clone $baseQuery)->whereNotNull('resolved_at')->get();
        $avgResolutionTime = 0;
        if ($resolvedTickets->count() > 0) {
            $totalDays = 0;
            foreach ($resolvedTickets as $ticket) {
                $totalDays += $ticket->created_at->diffInDays($ticket->resolved_at);
            }
            $avgResolutionTime = round($totalDays / $resolvedTickets->count(), 1);
        }

        // Distribuciones. Se agrupa por clave foránea y la etiqueta sale del
        // catálogo (R2). Antes se fabricaba con `ucfirst(str_replace('_',' '))`
        // sobre el código, que producía "In progress" —inglés y con la forma que
        // impusiera el código— en una interfaz en español. Ahora dice lo que el
        // catálogo dice, y cambiarlo es editar una fila, no desplegar.
        $distribucion = fn (string $columna, string $tabla, string $clave) =>
            (clone $baseQuery)->select($columna, DB::raw('count(*) as count'))
                ->whereNotNull($columna)
                ->groupBy($columna)
                ->get()
                ->map(fn ($item) => [
                    $clave  => $this->catalogs->label($tabla, (int) $item->{$columna}),
                    'code'  => $this->catalogs->code($tabla, (int) $item->{$columna}),
                    'count' => $item->count,
                ]);

        $byPriority = $distribucion('priority_id', TicketCatalogs::PRIORITY, 'priority');
        $byStatus   = $distribucion('status_id',   TicketCatalogs::STATUS,   'status');
        $byCategory = $distribucion('category_id', TicketCatalogs::CATEGORY, 'category');

        // Tendencia mensual (últimos 6 meses)
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $count = (clone $baseQuery)->whereBetween('created_at', [$monthStart, $monthEnd])->count();

            $monthlyTrend[] = [
                'month' => $monthStart->locale('es')->format('M'),
                'count' => $count
            ];
        }

        // Tickets recientes
        $recentTickets = (clone $baseQuery)->with(['user', 'staff'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'total_tickets' => $totalTickets,
            'open_tickets' => $openTickets,
            'in_progress_tickets' => $inProgressTickets,
            'resolved_this_month' => $resolvedThisMonth,
            'avg_resolution_time' => $avgResolutionTime,
            'by_priority' => $byPriority,
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'monthly_trend' => $monthlyTrend,
            'recent_tickets' => $recentTickets
        ]);
    }

    /**
     * Add a message to a ticket.
     */
    public function addMessage(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'message' => 'required|string',
            'is_internal' => 'boolean',
            'user_id' => 'sometimes|nullable|exists:users,id',
        ]);

        DB::beginTransaction();

        try {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $data['user_id'] ?? Auth::id() ?? 1,
                'message' => $data['message'],
                'is_internal' => $data['is_internal'] ?? false,
            ]);

            DB::commit();

            $message->load('user');

            // Enviar email si no es nota interna
            if (!$message->is_internal) {
                try {
                    $ticket->load('user', 'staff');
                    Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'message'));
                    if ($ticket->staff) {
                        Mail::to($ticket->staff->email)->send(new SendTicketNotification($ticket, 'message'));
                    }
                } catch (\Exception $e) {
                    \Log::error('Error sending message notification email: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Mensaje agregado correctamente. ✅',
                'ticket_message' => $message
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al agregar el mensaje.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', $this->reglaDe(TicketCatalogs::STATUS)],
        ]);

        DB::beginTransaction();

        try {
            $oldStatus = $ticket->status;
            $ticket->status = $data['status'];

            // Si cambia a resuelto, actualizar resolved_at
            if ($data['status'] === SupportTicket::STATUS_RESOLVED && $oldStatus !== SupportTicket::STATUS_RESOLVED) {
                $ticket->resolved_at = now();
            }

            $ticket->save();

            DB::commit();

            $ticket->load(['user', 'staff']);

            // Enviar email de notificación
            try {
                Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'updated'));
            } catch (\Exception $e) {
                \Log::error('Error sending status update notification email: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Estado actualizado correctamente. ✅',
                'ticket' => $ticket
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el estado.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a ticket message.
     */
    public function updateMessage(Request $request, $id)
    {
        $message = SupportTicketMessage::findOrFail($id);

        $data = $request->validate([
            'message' => 'required|string',
        ]);

        $message->update($data);

        return response()->json([
            'message' => 'Mensaje actualizado correctamente. ✅',
            'ticket_message' => $message->load('user')
        ]);
    }

    /**
     * Delete a ticket message.
     */
    public function deleteMessage($id)
    {
        $message = SupportTicketMessage::findOrFail($id);
        $message->delete();

        return response()->json([
            'message' => 'Mensaje eliminado correctamente. ✅'
        ]);
    }

    /**
     * Generate a charge invoice linked to this ticket.
     */
    public function generateCharge(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit'        => 'nullable|string|max:30',
            'items.*.unit_price'  => 'required|numeric|min:0',
            'items.*.type'        => 'nullable|string|max:50',
            'due_date'            => 'nullable|date',
            'notes'               => 'nullable|string',
        ]);

        try {
            $billingService = app(BillingService::class);

            $invoice = $billingService->generateServiceChargeInvoice([
                'tenant_id'   => $ticket->tenant_id,
                'customer_id' => $ticket->user_id,
                'ticket_id'   => $ticket->id,
                'items'       => $data['items'],
                'due_date'    => $data['due_date'] ?? null,
                'notes'       => $data['notes'] ?? "Cargo por ticket #{$ticket->id}: {$ticket->subject}",
            ]);

            return response()->json([
                'message' => 'Cargo generado correctamente. ✅',
                'invoice' => $invoice,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error generating ticket charge: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al generar el cargo.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all charge invoices linked to this ticket.
     */
    public function getCharges($id)
    {
        $ticket  = SupportTicket::findOrFail($id);
        $charges = Invoice::with(['items', 'payments'])
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($charges);
    }
}
