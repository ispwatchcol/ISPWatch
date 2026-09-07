<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketHistory;
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
                $this->guardarAdjuntos($request, $ticket, $data['user_id'] ?? $request->user()?->id);
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
                $this->guardarAdjuntos($request, $ticket, $request->user()?->id);
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
     * Guarda los adjuntos de una petición en el disco remoto.
     *
     * POR QUÉ `s3` Y NO EL DISCO LOCAL
     *
     * El disco de App Platform es EFÍMERO y por instancia: lo que se sube vive
     * hasta el siguiente despliegue. Así se perdió `sp1.jpg` — la fila seguía en
     * la base, la lista lo mostraba, y la imagen salía rota. Además el
     * `run_command` del despliegue no ejecuta `storage:link`, así que la ruta
     * `/storage/…` con la que se servían no existía siquiera.
     *
     * `s3` es el mismo disco donde ya viven los documentos de cliente
     * (`CustomerDocumentController`), así que no se introduce infraestructura
     * nueva: se deja de usar la que no funciona.
     *
     * El nombre se limpia como en documentos de cliente. `time()` a secas
     * colisionaba entre archivos subidos en el mismo segundo; con `uniqid` no.
     */
    private function guardarAdjuntos(Request $request, SupportTicket $ticket, ?int $autorId): void
    {
        foreach ($request->file('attachments') as $file) {
            $nombreLimpio = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
            $fileName = time() . '_' . uniqid() . '_' . $nombreLimpio;

            $filePath = $file->storeAs("support_attachments/{$ticket->id}", $fileName, 's3');

            $adjunto = SupportTicketAttachment::create([
                'ticket_id' => $ticket->id,
                'user_id'   => $autorId,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);

            // PR #3 · La RUTA DE ALMACENAMIENTO NO ENTRA en el historial. Es un
            // dato interno del bucket privado y el historial se muestra en
            // pantalla; publicarla anularía lo que se acaba de cerrar al mover
            // los adjuntos a `s3` con endpoint autenticado. Van el nombre que
            // ve el usuario, el tamaño y el id, con el que se arma la URL
            // autorizada si hace falta.
            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::ATTACHMENT_ADDED,
                metadata: [
                    'attachment_id' => $adjunto->id,
                    'file_name'     => $adjunto->file_name,
                    'file_size'     => $adjunto->file_size,
                    'mime_type'     => $adjunto->mime_type,
                ],
            );
        }
    }

    /**
     * El borrado físico de un ticket está PROHIBIDO. Esto lo rechaza.
     *
     * QUÉ HABÍA AQUÍ Y POR QUÉ SE RETIRÓ
     *
     * Hasta este PR existía un `destroy()` que borraba el ticket de verdad. Tres
     * problemas encadenados, ninguno visible desde la interfaz:
     *
     *  1. Estaba tras `permission:view_support` — el MISMO permiso que leer. Los
     *     roles `Tecnico` y `Staff` de todos los ISP lo tienen. Quien podía ver
     *     un ticket podía destruirlo, y la única barrera era un `confirm()` del
     *     navegador.
     *
     *  2. Desde el PR #3, `support_ticket_history` cuelga del ticket con
     *     `ON DELETE CASCADE`. Borrar el ticket **borraba su auditoría**, que es
     *     exactamente lo que esa tabla existe para impedir. El requerimiento del
     *     cliente pide «historial inalterable» y «revisión sin alterar el
     *     expediente» (Solicitud Maestra §18); un botón que lo evapora es lo
     *     contrario.
     *
     *  3. Borraba los adjuntos de `Storage::disk('public')`, pero el PR #252 los
     *     movió a `s3`. Es decir: no borraba el archivo del bucket —quedaban
     *     huérfanos con datos del cliente— y sí borraba la fila que decía dónde
     *     estaba. Lo peor de los dos mundos.
     *
     * Y una cuarta consecuencia: `invoices.ticket_id` es `nullOnDelete()`, así
     * que un cargo facturado quedaba sin expediente de origen.
     *
     * POR QUÉ 403 Y NO SIMPLEMENTE QUITAR LA RUTA
     *
     * Una ruta inexistente devuelve un 405 escueto que no explica nada, y quien
     * lo reciba pensará que es un fallo. Aquí el rechazo es deliberado y se dice
     * por qué. El método NO TOCA LA BASE DE DATOS: ni siquiera busca el ticket,
     * para que no exista camino alguno hacia un `delete()`.
     *
     * QUÉ VIENE DESPUÉS
     *
     * El archivado/anulación reversible y auditado es el PR C del diseño
     * (`docs/cliente/CNO/DISENO_PERMISOS_Y_ARCHIVADO.md`). Se dejó fuera de aquí
     * a propósito: este PR sólo cierra el agujero, y mezclar una funcionalidad
     * nueva con un correctivo de seguridad retrasa el correctivo.
     */
    public function destroy($id)
    {
        return response()->json([
            'message' => 'Los tickets no se pueden eliminar. El expediente y su historial '
                . 'deben conservarse. El archivado reversible estará disponible más adelante.',
            'error'   => 'ticket_deletion_disabled',
        ], 403);
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

        // `user_id` YA NO SE ACEPTA. Antes venía en el cuerpo, lo mandaba
        // SupportDetail.vue leyéndolo de `localStorage.userData` — donde la
        // sesión sólo está si se marcó «recordarme»; si no, vive en
        // `sessionStorage`. Sin ese dato la interfaz caía al literal `1`, que en
        // producción no corresponde a ningún usuario, y `exists:users,id`
        // devolvía 422 en cada intento de guardar una nota.
        //
        // Arreglar la lectura del storage habría tapado el síntoma. El problema
        // de fondo es que la AUTORÍA la decidía el cliente: cualquiera podía
        // firmar una nota en nombre de otro cambiando el payload. El autor sale
        // ahora de la sesión, que es la única fuente que no se puede falsificar.
        $data = $request->validate([
            'message'     => 'required|string|max:5000',
            'is_internal' => 'sometimes|boolean',
        ], [
            'message.required' => 'La nota no puede estar vacía.',
            'message.max'      => 'La nota no puede superar los 5000 caracteres.',
        ]);

        $autor = $request->user()->id;

        // Reenvío accidental (doble clic, o el usuario que reintenta al ver la
        // pantalla quieta). Se devuelve la nota que ya existe en vez de crear
        // una gemela. La ventana es corta a propósito: repetir literalmente la
        // misma frase pasados unos segundos es una nota nueva legítima.
        $reciente = SupportTicketMessage::where('ticket_id', $ticket->id)
            ->where('user_id', $autor)
            ->where('message', $data['message'])
            ->where('created_at', '>=', now()->subSeconds(10))
            ->latest('id')
            ->first();

        if ($reciente) {
            $reciente->load('user');

            return response()->json([
                'message'        => 'La nota ya estaba guardada.',
                'ticket_message' => $reciente,
            ]);
        }

        DB::beginTransaction();

        try {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $autor,
                'message' => $data['message'],
                'is_internal' => $data['is_internal'] ?? false,
            ]);

            // PR #3 · Se registra QUE se anotó, no el contenido: la nota ya se
            // lee entera en la bitácora de trabajo, justo encima del historial.
            // Duplicarla aquí la volvería inmutable por la puerta de atrás —el
            // historial no se puede editar y la nota sí— y dejaría dos copias
            // que pueden divergir.
            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::NOTE_ADDED,
                metadata: [
                    'note_id'     => $message->id,
                    'is_internal' => (bool) $message->is_internal,
                ],
            );

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

            // PR #3 · Referencia, no copia. El importe y el detalle viven en la
            // factura y allí se mantienen —se anulan, se pagan—; duplicarlos en
            // un registro inmutable dejaría una cifra que envejece y que alguien
            // acabaría leyendo como buena. Va el número, que es lo que permite
            // ir a buscarla.
            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::CHARGE_CREATED,
                metadata: array_filter([
                    'invoice_id'     => $invoice->id ?? null,
                    'invoice_number' => $invoice->invoice_number ?? null,
                ], fn ($v) => $v !== null),
            );

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
    /**
     * Historial inalterable del ticket. PR #3 · F1-17.
     *
     * SÓLO LECTURA, y no por omisión: no existe endpoint de edición ni de
     * borrado, y el modelo lanza si alguien lo intenta por código. El
     * requerimiento pide que «la auditoría no sea editable desde la operación
     * ordinaria», y eso incluye no ofrecer la puerta.
     *
     * Paginado porque un ticket vivo acumula decenas de eventos y la pantalla
     * los carga bajo demanda. Orden descendente: lo último es lo que interesa.
     *
     * El aislamiento sale de `findOrFail` —que pasa por el scope de tenant— y
     * además se filtra por `tenant_id` en la propia consulta: un evento con el
     * tenant mal estampado no debe poder colarse porque el ticket sí sea visible.
     */
    public function history(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $eventos = SupportTicketHistory::with('actor:id,user_name,user_lastname,email')
            ->where('support_ticket_id', $ticket->id)
            ->where('tenant_id', $ticket->tenant_id)
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($eventos);
    }

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
