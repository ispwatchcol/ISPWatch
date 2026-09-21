<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketHistory;
use App\Models\User;
use App\Services\BillingService;
use App\Support\TicketCatalogs;
use App\Support\TicketWorkflow;
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

        // PR B · La ruta ya exigió `ticket_create`, que cubre el acto de abrir
        // el ticket con su asunto, su categoría y su técnico asignado.
        // Diagnosticar y adjuntar evidencia son capacidades distintas y se
        // exigen también al crear: si no, quien no puede diagnosticar un ticket
        // existente podría hacerlo colando los campos en el alta.
        if ($falta = $this->permisoQueFaltaAlCrear($request, $data)) {
            return $this->negar($falta);
        }

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
                // El estado de apertura sale del CATÁLOGO (`is_initial`), no de
                // una constante. La Solicitud Maestra abre en `radicado`, y
                // dejarlo escrito aquí habría obligado a desplegar código para
                // cambiar el primer paso del flujo.
                'status' => $this->catalogs->estadoInicial() ?? SupportTicket::STATUS_OPEN,
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
     *
     * PR C · Punto de lectura que SÍ debe ver lo archivado, para quien puede
     * archivar o restaurar. Sin esto, archivar un ticket lo volvería
     * inaccesible incluso para quien tiene que revisarlo antes de restaurarlo,
     * y el «expediente completamente reconstruible» del diseño sería falso.
     *
     * Para todos los demás sigue siendo un 404, no un 403: quien no puede ver
     * archivados tampoco debe poder deducir que ese ticket existe.
     */
    public function show(Request $request, $id)
    {
        $ticket = $this->buscarTicket($request, $id, [
            'user',
            'staff',
            'messages.user',
            'attachments.user',
            'archiver:id,user_name,user_lastname',
        ]);

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
            // `status` SALIÓ DE AQUÍ. El estado ya no se mueve por el formulario
            // de edición: un cambio de estado es una TRANSICIÓN, con estado
            // origen válido, permiso propio y motivo, y va por
            // `PATCH /support/{id}/status`. Mientras estuvo en este `PUT`,
            // cualquiera con `ticket_transition` podía saltar de `radicado` a
            // `cerrado` sin causa confirmada y sin que nada lo notara.
            //
            // Se ignora si llega, en vez de dar 422: la pantalla de edición
            // reenvía el formulario entero, y rechazar la petición por un campo
            // que el usuario no tocó rompería el guardado.
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

        // PR B · AUTORIZACIÓN POR CAMPO.
        //
        // Este endpoint hace seis cosas distintas —editar contenido, asignar
        // técnico, cambiar prioridad, cambiar categoría, registrar diagnóstico y
        // subir adjuntos— y cada una tiene su permiso. La ruta sólo garantiza
        // `ticket_view`, que es la puerta mínima para tocar un ticket; el
        // reparto fino tiene que ocurrir aquí, que es donde se sabe QUÉ campos
        // trae la petición.
        if ($falta = $this->permisoQueFalta($request, $ticket, $data)) {
            return $this->negar($falta);
        }

        DB::beginTransaction();

        try {
            $ticket->update($data);

            // Aquí había un bloque que estampaba `resolved_at` cuando el `PUT`
            // movía el estado a resuelto. Ya no puede ocurrir: `status` salió de
            // la validación y el estampado lo decide el catálogo
            // (`stamps_resolved_at`) desde la transición. Ver `transicionar()`.

            // Subir archivos adjuntos si existen en update
            if ($request->hasFile('attachments')) {
                $this->guardarAdjuntos($request, $ticket, $request->user()?->id);
            }

            DB::commit();

            $ticket->load(['user', 'staff', 'messages', 'attachments']);

            // El correo por cambio de estado lo manda ahora la transición, que
            // es el único camino por el que el estado se mueve.

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
     * Campo de la petición => permiso que hace falta para tocarlo.
     *
     * `status` NO está aquí: el cambio de estado por `PUT` se trata aparte más
     * abajo, porque cerrar exige un permiso distinto de transicionar.
     */
    private const PERMISO_POR_CAMPO = [
        'subject'         => Permissions::TICKET_EDIT,
        'description'     => Permissions::TICKET_EDIT,
        'sectorial_id'    => Permissions::TICKET_EDIT,
        'staff_id'        => Permissions::TICKET_ASSIGN,
        'priority'        => Permissions::TICKET_SET_PRIORITY,
        'category'        => Permissions::TICKET_SET_CATEGORY,
        'symptom'         => Permissions::TICKET_DIAGNOSE,
        'suspected_cause' => Permissions::TICKET_DIAGNOSE,
        'solution'        => Permissions::TICKET_DIAGNOSE,
        'result'          => Permissions::TICKET_DIAGNOSE,
        // Confirmar la causa es una potestad aparte en el requerimiento: el
        // documento se la da al Supervisor, no a quien diagnostica.
        'confirmed_cause' => Permissions::TICKET_CONFIRM_CAUSE,
    ];

    /**
     * Primer permiso que le falta a quien hace la petición, o `null` si los
     * tiene todos.
     *
     * Se mira SÓLO lo que la petición trae. Un `PUT` que reenvía el formulario
     * entero —que es lo que hace la pantalla de edición— exigiría todos los
     * permisos aunque no cambie nada; por eso se comparan los valores contra el
     * ticket y se ignoran los campos que llegan iguales. Sin eso, separar los
     * permisos rompería la pantalla para cualquiera que no los tuviera todos.
     *
     * @param  array<string, mixed>  $data
     */
    private function permisoQueFalta(Request $request, SupportTicket $ticket, array $data): ?string
    {
        $usuario = $request->user();

        $cambia = fn (string $campo, $valor): bool
            // Comparación laxa a propósito: un `staff_id` llega como "7" desde
            // un formulario y vale 7 en la base.
            => $ticket->{$campo} != $valor;

        foreach (self::PERMISO_POR_CAMPO as $campo => $permiso) {
            if (!array_key_exists($campo, $data) || !$cambia($campo, $data[$campo])) {
                continue;
            }

            if (!$usuario->hasPermission($permiso)) {
                return $permiso;
            }
        }

        if ($request->hasFile('attachments') && !$usuario->hasPermission(Permissions::TICKET_ATTACH)) {
            return Permissions::TICKET_ATTACH;
        }

        // El estado YA NO VIAJA POR AQUÍ. Se movía con `ticket_transition` —y
        // con `ticket_close` si el destino era cerrado— desde el mismo `PUT` que
        // edita el asunto. Ahora es una transición con estado origen válido,
        // requisitos de cierre y motivo. Ver `transicionar()`.

        return null;
    }

    /**
     * Permiso que falta para los campos del ALTA que no cubre `ticket_create`.
     *
     * @param  array<string, mixed>  $data
     */
    private function permisoQueFaltaAlCrear(Request $request, array $data): ?string
    {
        $usuario = $request->user();

        foreach (self::CATALOGOS_DIAGNOSTICO as $campo => $tabla) {
            if (($data[$campo] ?? null) === null) {
                continue;
            }

            $permiso = $campo === 'confirmed_cause'
                ? Permissions::TICKET_CONFIRM_CAUSE
                : Permissions::TICKET_DIAGNOSE;

            if (!$usuario->hasPermission($permiso)) {
                return $permiso;
            }
        }

        if ($request->hasFile('attachments') && !$usuario->hasPermission(Permissions::TICKET_ATTACH)) {
            return Permissions::TICKET_ATTACH;
        }

        return null;
    }

    /** 403 uniforme que dice QUÉ permiso falta, sin revelar nada más. */
    private function negar(string $permiso)
    {
        return response()->json([
            'message'             => 'No tienes permiso para realizar esa acción sobre el ticket.',
            'required_permission' => $permiso,
        ], 403);
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
                . 'deben conservarse. Para retirar un ticket de la operación usa '
                . 'POST /api/support/{id}/archive, que es reversible y queda auditado.',
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

        // Se cuenta por EQUIVALENCIA, no por el código exacto. Con los estados
        // de la Solicitud Maestra, «abiertos» ya no son sólo los `open`: son
        // también `radicado` y `en_clasificacion`, que es a lo que equivalen.
        // Contar por código exacto habría dejado el tablero en cero el día del
        // despliegue, sin que ningún test lo viera.
        $idsDe = fn (string $legacy) => $this->catalogs->idsEquivalentesA($legacy);

        $totalTickets = (clone $baseQuery)->count();
        $openTickets = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_OPEN))->count();
        $inProgressTickets = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_IN_PROGRESS))->count();

        // Tickets resueltos este mes
        $startOfMonth = now()->startOfMonth();
        $resolvedThisMonth = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_RESOLVED))
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

    // ─────────────────────────────────────────────────────────────────────
    // Workflow formal · estados, transiciones, cierre y reapertura
    //
    // Fuente: Solicitud_Maestra_ISPwash_CNO_V1_1.docx §7 (ciclo de vida),
    // §15 (reglas obligatorias de cierre) y §18 (roles). CNO confirmó los
    // estados y transiciones por chat el 11/09/2026 y delegó en el equipo la
    // definición operativa del cierre, las excepciones y la reapertura.
    //
    // POR QUÉ EL ESTADO SALIÓ DEL `PUT`
    //
    // Hasta ahora el estado se movía desde el formulario de edición, con
    // `ticket_transition`, y desde `PATCH .../status` sin comprobar de dónde
    // venía. Se podía saltar de `radicado` a `cerrado` sin causa confirmada,
    // sin acción y sin resultado — las tres primeras reglas del §15— y el
    // historial sólo dejaba constancia de que alguien lo hizo.
    // ─────────────────────────────────────────────────────────────────────

    /** Motivo obligatorio con la misma forma en todo el módulo (PR C). */
    private const REGLA_MOTIVO = 'required|string|min:10|max:500';

    private const MENSAJES_MOTIVO = [
        'reason.required' => 'El motivo es obligatorio.',
        'reason.min'      => 'El motivo debe explicar la decisión: mínimo 10 caracteres.',
        'reason.max'      => 'El motivo no puede pasar de 500 caracteres.',
    ];

    /**
     * Qué puede hacer AHORA quien pide, con este ticket.
     *
     * Existe para que la interfaz no mantenga su propia copia de la matriz. Una
     * segunda copia en JavaScript se desincroniza el día que alguien toca la
     * primera, y entonces el panel ofrece botones que la API rechaza.
     */
    public function transitions(Request $request, $id)
    {
        $ticket  = $this->buscarTicket($request, $id);
        $usuario = $request->user();
        $actual  = $ticket->status;

        $puede = fn (string $permiso): bool => (int) $usuario->role_id === 1
            || $usuario->hasPermission($permiso);

        $destinos = [];

        foreach (TicketWorkflow::destinosDesde($actual) as $code) {
            // Cerrar no se ofrece aquí: tiene endpoint propio, permiso propio y
            // requisitos que comprobar. Mezclarlo con el resto haría que un
            // `PATCH .../status` con destino `cerrado` saltara el §15.
            if ($code === TicketWorkflow::CERRADO) {
                continue;
            }

            $destinos[] = [
                'code'  => $code,
                'label' => $this->catalogs->label(TicketCatalogs::STATUS, $this->catalogs->id(TicketCatalogs::STATUS, $code)),
            ];
        }

        $faltantes = $this->requisitosFaltantes($ticket, TicketWorkflow::REQUISITOS_DE_CIERRE);

        // POR QUÉ no se puede reabrir, además de si se puede.
        //
        // Sin esto, un administrador abría un ticket cerrado y no veía el botón
        // «Reabrir» por ningún lado, sin ninguna pista de si faltaba un permiso,
        // si el estado no lo admitía o si la pantalla estaba rota. La interfaz
        // no debe adivinarlo —no conoce los permisos del servidor— así que lo
        // dice el servidor, que es el único que lo sabe.
        $reopenBlocked = match (true) {
            $ticket->is_archived                            => 'archived',
            !TicketWorkflow::sePuedeReabrir($actual)         => 'not_closed',
            !$puede(Permissions::TICKET_REOPEN)              => 'permission',
            default                                          => null,
        };

        return response()->json([
            'status'      => $actual,
            'status_label' => $this->etiquetaDe($actual),
            'is_archived' => $ticket->is_archived,
            // Un terminal no ofrece transiciones ordinarias, y la pantalla
            // necesita saberlo para explicarlo en vez de no pintar nada.
            'is_terminal' => TicketWorkflow::esTerminal($actual),
            // Qué transiciones ordinarias caben desde aquí.
            'transitions' => $ticket->is_archived || !$puede(Permissions::TICKET_TRANSITION) ? [] : $destinos,
            // Y las cuatro operaciones con nombre propio.
            'actions'     => [
                'propose_closure' => !$ticket->is_archived
                    && $puede(Permissions::TICKET_TRANSITION)
                    && in_array($actual, TicketWorkflow::PUEDE_PROPONER_DESDE, true),
                'close' => !$ticket->is_archived
                    && $puede(Permissions::TICKET_CLOSE)
                    && in_array($actual, TicketWorkflow::PUEDE_CERRAR_DESDE, true),
                'close_exception' => !$ticket->is_archived
                    && $puede(Permissions::TICKET_CLOSE_OVERRIDE)
                    && in_array($actual, TicketWorkflow::PUEDE_CERRAR_DESDE, true),
                'reopen' => !$ticket->is_archived
                    && $puede(Permissions::TICKET_REOPEN)
                    && TicketWorkflow::sePuedeReabrir($actual),
            ],
            // Lo que le falta al expediente para poder cerrarse. La interfaz lo
            // muestra ANTES de abrir el modal, para que nadie se entere de que
            // falta la causa confirmada después de escribir el motivo.
            'closure_requirements' => [
                'missing'  => array_values($faltantes),
                'complete' => $faltantes === [],
            ],
            // `null` cuando sí se puede. `permission` es el caso que motivó
            // este correctivo: el permiso existía y no se había repartido.
            'reopen_blocked_by'   => $reopenBlocked,
            'reopen_permission'   => Permissions::TICKET_REOPEN,
        ]);
    }

    /**
     * Transición ordinaria. Valida ORIGEN y destino contra la matriz.
     *
     * No cierra ni reabre: los dos tienen endpoint propio porque llevan
     * permisos y requisitos distintos.
     */
    public function updateStatus(Request $request, $id)
    {
        // Sin `withTrashed()`: un ticket archivado está fuera de la operación y
        // no se mueve. Para tocarlo hay que restaurarlo primero, que es una
        // decisión con su propio permiso y su motivo.
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', $this->reglaDe(TicketCatalogs::STATUS)],
            // Opcional en una transición ordinaria: mover un ticket de
            // «asignado» a «en intervención» no necesita justificarse. Si llega,
            // se guarda.
            'reason' => 'nullable|string|max:500',
        ]);

        $destino = $data['status'];

        if ($destino === $ticket->status) {
            // Ni evento ni escritura. El requerimiento pide explícitamente que
            // repetir el mismo estado no genere historial, y responder 200 en
            // silencio dejaría al operador creyendo que hizo algo.
            return response()->json([
                'message' => 'El ticket ya está en ese estado.',
                'error'   => 'ticket_same_status',
                'status'  => $ticket->status,
            ], 422);
        }

        // Cerrar y REABRIR no pasan por aquí: cada uno tiene su endpoint, su
        // permiso y sus requisitos. Se rechazan por una tabla y no por dos `if`
        // encadenados, para que añadir una operación con nombre propio no deje
        // un destino alcanzable por la puerta genérica.
        if ($ruta = TicketWorkflow::DESTINOS_CON_ENDPOINT_PROPIO[$destino] ?? null) {
            $esCierre = $ruta === 'close';

            return response()->json([
                'message' => ($esCierre
                    ? 'Cerrar un ticket no es una transición más: usa POST /api/support/'
                    : 'Reabrir un ticket no es una transición más: usa POST /api/support/')
                    . $ticket->id . '/' . $ruta
                    . ($esCierre
                        ? ', que comprueba los requisitos de cierre.'
                        : ', que exige `ticket_reopen` y un motivo.'),
                'error'   => $esCierre ? 'ticket_close_requires_endpoint' : 'ticket_reopen_requires_endpoint',
            ], 422);
        }

        if ($respuesta = $this->rechazarTransicion($ticket, $destino)) {
            return $respuesta;
        }

        $anterior = $this->transicionar($ticket, $destino, $data['reason'] ?? null);

        return response()->json([
            'message'         => 'Estado actualizado correctamente. ✅',
            'previous_status' => $anterior,
            'ticket'          => $ticket->fresh(['user', 'staff']),
        ]);
    }

    /**
     * PROPUESTA DE CIERRE. §18 se la da al Técnico de campo, tras las pruebas
     * finales.
     *
     * NO CIERRA EL TICKET, y ése es todo el punto: separa a quien hizo el
     * trabajo de quien acredita que está bien hecho. Deja el ticket en
     * «En observación» —el estado que el documento coloca justo antes de
     * CERRADO— y un evento con la acción, el resultado y la observación.
     *
     * Exige acción y resultado (reglas 2 y 3 del §15) pero NO causa confirmada:
     * confirmar la causa es potestad del Supervisor, también según §18.
     */
    public function proposeClosure(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        if (!in_array($ticket->status, TicketWorkflow::PUEDE_PROPONER_DESDE, true)) {
            return response()->json([
                'message' => 'Sólo se puede proponer el cierre de un ticket cuyo servicio ya está '
                    . 'restablecido. Este ticket está en «' . $this->etiquetaDe($ticket->status) . '».',
                'error'   => 'ticket_cannot_propose_closure',
                'status'  => $ticket->status,
            ], 422);
        }

        $data = $request->validate(
            ['reason' => self::REGLA_MOTIVO],
            self::MENSAJES_MOTIVO + [
                'reason.required' => 'La observación técnica de la propuesta es obligatoria.',
            ],
        );

        if ($faltantes = $this->requisitosFaltantes($ticket, TicketWorkflow::REQUISITOS_DE_PROPUESTA)) {
            return $this->rechazarPorRequisitos($faltantes, 'proponer el cierre');
        }

        $destino  = TicketWorkflow::ESTADO_TRAS_PROPUESTA;
        $anterior = $ticket->status;

        DB::transaction(function () use ($ticket, $destino, $data, $anterior) {
            // El ticket puede estar YA en observación: entonces la propuesta no
            // mueve el estado, sólo deja el evento. Sin esta comprobación se
            // intentaría una transición de un estado a sí mismo.
            if ($anterior !== $destino) {
                $this->transicionar($ticket, $destino, null, notificar: false);
            }

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::CLOSURE_PROPOSED,
                metadata: array_filter([
                    'reason'    => $data['reason'],
                    'from'      => $anterior,
                    'solution'  => $ticket->solution,
                    'result'    => $ticket->result,
                ], fn ($v) => $v !== null),
            );
        });

        return response()->json([
            'message'         => 'Cierre propuesto. El ticket queda a la espera de la revisión del supervisor. 📋',
            'previous_status' => $anterior,
            'ticket'          => $ticket->fresh(['user', 'staff']),
        ]);
    }

    /**
     * CIERRE NORMAL. §18 se lo da al Supervisor; exige `ticket_close`.
     *
     * Comprueba las tres reglas del §15 que el modelo de datos puede sostener
     * hoy: causa confirmada, acción y resultado. Las otras siete quedan
     * documentadas en `TicketWorkflow::REQUISITOS_DE_CIERRE` y son la razón por
     * la que F1-10 sigue PARCIAL: exigen campos de captura que el ticket no
     * tiene todavía.
     */
    public function close(Request $request, $id)
    {
        return $this->cerrar($request, $id, excepcion: false);
    }

    /**
     * CIERRE EXCEPCIONAL. §15.1 lo contempla —«salvo excepción autorizada y
     * justificada»— y §18 se lo da al Supervisor como «cierre especial».
     *
     * NO es un cierre normal con otro nombre: deja un evento propio, registra
     * QUÉ requisito faltaba y por qué se autorizó, y exige
     * `ticket_close_override`, que nadie tiene por defecto.
     */
    public function closeException(Request $request, $id)
    {
        return $this->cerrar($request, $id, excepcion: true);
    }

    private function cerrar(Request $request, $id, bool $excepcion)
    {
        $ticket = SupportTicket::findOrFail($id);

        if (TicketWorkflow::esTerminal($ticket->status)) {
            return response()->json([
                'message' => 'El ticket ya está cerrado.',
                'error'   => 'ticket_already_closed',
                'status'  => $ticket->status,
            ], 422);
        }

        if (!in_array($ticket->status, TicketWorkflow::PUEDE_CERRAR_DESDE, true)) {
            return response()->json([
                'message' => 'No se puede cerrar un ticket en «' . $this->etiquetaDe($ticket->status)
                    . '»: el servicio todavía no está restablecido. El documento separa restablecer '
                    . 'de cerrar, y cerrar sin restablecer deja el expediente diciendo algo que no pasó.',
                'error'   => 'ticket_cannot_close_from_status',
                'status'  => $ticket->status,
            ], 422);
        }

        $reglas = $excepcion
            ? ['reason' => self::REGLA_MOTIVO]
            : ['reason' => 'nullable|string|max:500'];

        $data = $request->validate($reglas, self::MENSAJES_MOTIVO + [
            'reason.required' => 'El cierre excepcional exige explicar por qué se autoriza sin '
                . 'cumplir todos los requisitos.',
        ]);

        $faltantes = $this->requisitosFaltantes($ticket, TicketWorkflow::REQUISITOS_DE_CIERRE);

        // §15.9: una solución temporal no se cierra sin «seguimiento o
        // autorización». El cierre excepcional ES esa autorización.
        if ($ticket->status === TicketWorkflow::SOLUCION_TEMPORAL) {
            $faltantes['solucion_temporal'] = 'Cierre con solución temporal: exige autorización (regla 9 del § 15)';
        }

        if (!$excepcion && $faltantes) {
            return $this->rechazarPorRequisitos($faltantes, 'cerrar');
        }

        if ($excepcion && !$faltantes) {
            // Sin nada que excepcionar, un «cierre excepcional» sería un cierre
            // normal con el permiso más alto y un evento que miente sobre lo
            // que pasó. Se rechaza y se dirige al cierre ordinario.
            return response()->json([
                'message' => 'Este ticket cumple todos los requisitos de cierre: ciérralo de forma '
                    . 'ordinaria. El cierre excepcional deja constancia de un requisito incumplido, '
                    . 'y usarlo sin que falte nada ensucia la auditoría.',
                'error'   => 'ticket_no_exception_needed',
            ], 422);
        }

        $anterior = $ticket->status;

        DB::transaction(function () use ($ticket, $data, $excepcion, $faltantes, $anterior) {
            $this->transicionar($ticket, TicketWorkflow::CERRADO, null, notificar: false);

            SupportTicketHistory::registrar(
                $ticket,
                $excepcion ? SupportTicketHistory::CLOSED_EXCEPTION : SupportTicketHistory::CLOSED,
                metadata: array_filter([
                    'reason' => $data['reason'] ?? null,
                    'from'   => $anterior,
                    // QUÉ requisito faltó. Es la mitad del valor de un cierre
                    // excepcional: sin esto sólo consta que alguien lo forzó.
                    'requisitos_incumplidos' => $excepcion ? array_values($faltantes) : null,
                    'confirmed_cause' => $ticket->confirmed_cause,
                    'solution'        => $ticket->solution,
                    'result'          => $ticket->result,
                ], fn ($v) => $v !== null && $v !== []),
            );
        });

        $this->notificarCambioDeEstado($ticket);

        return response()->json([
            'message' => $excepcion
                ? 'Ticket cerrado por excepción. El motivo y el requisito incumplido quedan en el historial. ⚠️'
                : 'Ticket cerrado. ✅',
            'previous_status' => $anterior,
            'exception'       => $excepcion,
            'ticket'          => $ticket->fresh(['user', 'staff']),
        ]);
    }

    /**
     * REAPERTURA. §7 la nombra como estado auxiliar («Reabierto») y el Anexo B
     * la trata como modalidad STR («la afectación reaparece después del
     * cierre»). Exige `ticket_reopen`.
     *
     * NO BORRA `closed_at`. El §19.5 pide que «los estados y timestamps se
     * conserven sin sobrescritura»: la fecha del cierre anterior sigue siendo
     * un hecho, y el historial guarda el evento completo de aquel cierre.
     */
    public function reopen(Request $request, $id)
    {
        // Un archivado no se reabre: primero hay que restaurarlo, que es otra
        // decisión con otro permiso. `findOrFail` sin `withTrashed()` lo cubre.
        $ticket = SupportTicket::findOrFail($id);

        // La tabla de reapertura, no `esTerminal()`: declara explícitamente de
        // qué estado se puede volver y a cuál, y deja la respuesta capaz de
        // decirlo. Un ticket ya reabierto cae aquí con el mismo error, que es
        // lo que pide «reapertura repetida recibe error claro».
        $destino = TicketWorkflow::estadoTrasReabrir($ticket->status);

        if ($destino === null) {
            return response()->json([
                'message' => 'Sólo se reabre un ticket cerrado. Este está en «'
                    . $this->etiquetaDe($ticket->status) . '».',
                'error'   => 'ticket_not_closed',
                'status'  => $ticket->status,
            ], 422);
        }

        $data = $request->validate(
            ['reason' => self::REGLA_MOTIVO],
            self::MENSAJES_MOTIVO + [
                'reason.required' => 'El motivo de la reapertura es obligatorio.',
            ],
        );

        $anterior  = $ticket->status;
        $cerradoEl = $ticket->closed_at?->toJSON();

        DB::transaction(function () use ($ticket, $data, $anterior, $cerradoEl, $destino) {
            // `closed_at` NO se limpia: el §19.5 pide que «los estados y
            // timestamps se conserven sin sobrescritura», y la fecha de aquel
            // cierre sigue siendo un hecho. `transicionar()` tampoco la toca
            // porque `reabierto` no declara `stamps_closed_at`.
            $this->transicionar($ticket, $destino, null, notificar: false);

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::REOPENED,
                metadata: array_filter([
                    'reason'    => $data['reason'],
                    'from'      => $anterior,
                    'to'        => $destino,
                    'closed_at' => $cerradoEl,
                ], fn ($v) => $v !== null),
            );
        });

        $this->notificarCambioDeEstado($ticket);

        return response()->json([
            'message'         => 'Ticket reabierto. La fecha del cierre anterior se conserva. ↩️',
            'previous_status' => $anterior,
            'ticket'          => $ticket->fresh(['user', 'staff']),
        ]);
    }

    // ── Piezas compartidas ───────────────────────────────────────────────

    /**
     * Aplica la transición: estado, timestamps y correo.
     *
     * El evento `status_changed` del historial NO se escribe aquí: lo pone el
     * observer al detectar el cambio de `status_id`. Escribirlo también desde
     * el controlador dejaría dos eventos por cada movimiento.
     *
     * @return string el estado anterior
     */
    private function transicionar(
        SupportTicket $ticket,
        string $destino,
        ?string $motivo = null,
        bool $notificar = true,
    ): string {
        $anterior = $ticket->status;

        $fila = $this->catalogs->estado($destino);

        $ticket->status = $destino;

        // Los timestamps los decide el CATÁLOGO, no una cadena de `if`. Añadir
        // un estado que estampe `resolved_at` es editar una fila, no desplegar.
        //
        // Y NO SE BORRAN NUNCA: «los estados y timestamps se conservan sin
        // sobrescritura» (§19.5). Un ticket que vuelve a intervención mantiene
        // el `resolved_at` del restablecimiento anterior.
        if ($fila?->stamps_resolved_at && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }

        if ($fila?->stamps_closed_at) {
            $ticket->closed_at = now();
        }

        $ticket->save();

        if ($motivo !== null && $motivo !== '') {
            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::TRANSITION_NOTE,
                field: 'status',
                metadata: ['reason' => $motivo, 'from' => $anterior, 'to' => $destino],
            );
        }

        if ($notificar) {
            $this->notificarCambioDeEstado($ticket);
        }

        return $anterior;
    }

    /** El correo al cliente nunca debe tumbar la transición. */
    private function notificarCambioDeEstado(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing('user');

            if ($ticket->user?->email) {
                Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'updated'));
            }
        } catch (\Exception $e) {
            \Log::error('Error sending status update notification email: ' . $e->getMessage());
        }
    }

    /** 422 uniforme cuando la matriz no admite el movimiento. */
    private function rechazarTransicion(SupportTicket $ticket, string $destino)
    {
        if (TicketWorkflow::permite($ticket->status, $destino)) {
            return null;
        }

        $posibles = array_map(fn ($c) => $this->etiquetaDe($c), TicketWorkflow::destinosDesde($ticket->status));

        return response()->json([
            'message' => 'No se puede pasar de «' . $this->etiquetaDe($ticket->status) . '» a «'
                . $this->etiquetaDe($destino) . '».'
                . ($posibles ? ' Desde aquí se puede ir a: ' . implode(', ', $posibles) . '.' : ''),
            'error'             => 'ticket_transition_not_allowed',
            'from'              => $ticket->status,
            'to'                => $destino,
            'allowed'           => TicketWorkflow::destinosDesde($ticket->status),
        ], 422);
    }

    /**
     * Requisitos del §15 que al expediente todavía le faltan.
     *
     * @param  array<string, string>  $requisitos  campo => descripción
     * @return array<string, string>
     */
    private function requisitosFaltantes(SupportTicket $ticket, array $requisitos): array
    {
        $faltantes = [];

        foreach ($requisitos as $campo => $descripcion) {
            if (blank($ticket->{$campo})) {
                $faltantes[$campo] = $descripcion;
            }
        }

        return $faltantes;
    }

    /** @param array<string, string> $faltantes */
    private function rechazarPorRequisitos(array $faltantes, string $accion)
    {
        return response()->json([
            'message' => 'No se puede ' . $accion . ': faltan ' . count($faltantes)
                . ' requisito(s) del expediente — ' . implode('; ', array_values($faltantes)) . '.',
            'error'   => 'ticket_closure_requirements_missing',
            'missing' => array_keys($faltantes),
            'details' => array_values($faltantes),
        ], 422);
    }

    /** Etiqueta del catálogo para un código, o el código si no hay fila. */
    private function etiquetaDe(?string $code): string
    {
        if ($code === null) {
            return '—';
        }

        return $this->catalogs->label(TicketCatalogs::STATUS, $this->catalogs->id(TicketCatalogs::STATUS, $code))
            ?? $code;
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
        // PR C · El historial de un ticket archivado sigue siendo consultable
        // para quien puede restaurarlo: es donde consta el motivo por el que se
        // archivó y quién lo decidió.
        $ticket = $this->buscarTicket($request, $id);

        $eventos = SupportTicketHistory::with('actor:id,user_name,user_lastname,email')
            ->where('support_ticket_id', $ticket->id)
            ->where('tenant_id', $ticket->tenant_id)
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($eventos);
    }

    public function getCharges(Request $request, $id)
    {
        // PR C · Un ticket archivado no debería tener cargos vivos —archivar lo
        // impide—, pero sí puede tener facturas anuladas, y la trazabilidad
        // contable tiene que poder verlas desde el expediente.
        $ticket  = $this->buscarTicket($request, $id);
        $charges = Invoice::with(['items', 'payments'])
            ->where('ticket_id', $ticket->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($charges);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PR C · Archivado y restauración de expedientes
    //
    // El PR A retiró el borrado físico del ticket y dejó un hueco deliberado:
    // no había forma de sacar de la vista un ticket abierto por error. CNO
    // aprobó el 2026-09-11 sustituirlo por archivado reversible y auditado
    // «para Administradores y Propietarios».
    //
    // «Propietario» NO EXISTE como rol en ISPWatch —los `code` son admin,
    // staff, technician, accounting y client—, así que se implementa como
    // `admin` más el superadministrador global. Supuesto S-1 del diseño.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Estados en los que archivar es casi siempre un error: hay trabajo vivo.
     *
     * Era la pareja `open`/`in_progress` escrita a mano. Con los dieciocho
     * estados de la Solicitud Maestra eso habría dejado un boquete: archivar un
     * ticket «En intervención» se habría saltado las dos barreras del PR C sin
     * que nada fallara. Ahora se deduce del workflow —todo lo que no es
     * terminal— así que añadir un estado no abre el agujero.
     */
    private function estadosActivos(): array
    {
        return TicketWorkflow::estadosActivos();
    }

    /**
     * Las dos únicas razones que justifican archivar trabajo en curso.
     *
     * Un ticket duplicado o abierto por error no es trabajo: es ruido que
     * ensucia las métricas. Cualquier otro motivo —«ya no aplica», «el cliente
     * no contesta»— describe un ticket que hay que CERRAR, no esconder, y el
     * cierre deja el expediente en las estadísticas donde debe estar.
     */
    private const MOTIVOS_SOBRE_ACTIVO = ['duplicate', 'registration_error'];

    /** Estados de factura que significan «este cargo ya no se cobra». */
    private const FACTURAS_ANULADAS = ['void', 'cancelled'];

    /**
     * ¿Puede quien pide ver los expedientes archivados?
     *
     * Replica la semántica de `CheckPermission`, bypass de superadministrador
     * incluido, porque esto se evalúa DENTRO del controlador en rutas que no
     * exigen el permiso por middleware (`show`, `history`, `getCharges`).
     */
    private function puedeVerArchivados(Request $request): bool
    {
        $usuario = $request->user();

        if (!$usuario) {
            return false;
        }

        return (int) $usuario->role_id === 1
            || $usuario->hasPermission(Permissions::TICKET_ARCHIVE)
            || $usuario->hasPermission(Permissions::TICKET_RESTORE);
    }

    /**
     * Busca un ticket incluyendo los archivados SÓLO si quien pide puede verlos.
     *
     * El aislamiento por tenant lo sigue poniendo el scope global de
     * `BelongsToTenant`, que `withTrashed()` no toca.
     *
     * @param  array<int, string>  $con
     */
    private function buscarTicket(Request $request, $id, array $con = []): SupportTicket
    {
        return SupportTicket::with($con)
            ->when($this->puedeVerArchivados($request), fn ($q) => $q->withTrashed())
            ->findOrFail($id);
    }

    /**
     * Listado de expedientes archivados. Sólo para quien archiva o restaura.
     *
     * Paginado y no `get()` como el listado ordinario: los archivados sólo
     * crecen —nada los saca de aquí salvo restaurarlos— y una consulta sin
     * límite envejece mal.
     */
    public function archived(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;

        $query = SupportTicket::onlyTrashed()->with([
            'user:id,user_name,user_lastname,email',
            'staff:id,user_name,user_lastname',
            'archiver:id,user_name,user_lastname',
        ]);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Los mismos filtros por código que el listado ordinario: quien busca
        // un archivado busca igual que siempre.
        foreach (self::CATALOGOS_FILTRABLES as $campo => [$columna, $tabla]) {
            if ($request->has($campo) && $request->{$campo} != 'all') {
                $query->where($columna, $this->catalogs->id($tabla, $request->{$campo}));
            }
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // `whereLike` elige ilike o like según el motor: con LIKE a secas
        // PostgreSQL distingue mayúsculas y la búsqueda falla en producción sin
        // fallar en los tests. Mismo criterio que `index()`.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereLike('subject', $search)
                  ->orWhereLike('description', $search)
                  ->orWhereLike('archived_reason', $search);
            });
        }

        return response()->json(
            $query->orderByDesc('deleted_at')
                  ->orderByDesc('id')
                  ->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    /**
     * Archiva un expediente. Reversible, auditado y con motivo obligatorio.
     *
     * CUATRO BARRERAS, Y NINGUNA VIVE SÓLO EN LA INTERFAZ
     *
     * 1. **Motivo de 10 a 500 caracteres.** Diez caracteres no garantizan una
     *    explicación, pero descartan «ok» y «ya» — y sobre todo obligan a
     *    detenerse. El motivo es la evidencia de la decisión.
     * 2. **Escribir el número del ticket** (`confirm_ticket_id`). Es la doble
     *    confirmación, y se valida en el servidor además de en el modal: un
     *    `confirm()` se acepta por reflejo, y una barrera que sólo existe en el
     *    navegador no es una barrera.
     * 3. **Trabajo vivo bloqueado.** Un ticket `open` o `in_progress` sólo se
     *    archiva por duplicado o error de registro, y con una confirmación
     *    adicional explícita.
     * 4. **Cargos vivos bloqueados.** Con una factura sin anular, archivar
     *    rompería la trazabilidad contable: el cargo seguiría cobrándose y su
     *    expediente habría desaparecido de la operación.
     *
     * NO SE BORRA NADA. Ni notas, ni adjuntos, ni cargos, ni historial, ni un
     * solo archivo del bucket — CNO dejó instrucción expresa de no purgar.
     */
    public function archive(Request $request, $id)
    {
        // Sin `withTrashed()` a propósito: archivar lo ya archivado es un 404,
        // no una operación idempotente que registraría un evento de más.
        $ticket = SupportTicket::findOrFail($id);

        $esActivo = in_array($ticket->status, $this->estadosActivos(), true);

        $reglas = [
            'reason'            => 'required|string|min:10|max:500',
            'confirm_ticket_id' => 'required',
        ];

        // Las dos barreras del trabajo vivo se AÑADEN, no se declaran siempre
        // con una condición dentro. `Rule::requiredIf(false)` se colapsa a una
        // cadena vacía pero no desactiva las reglas que van a su lado: con
        // `['accepted']` al lado, un ticket cerrado exigía igualmente la
        // casilla. Construir el conjunto de reglas es más claro y no tiene
        // esquinas.
        if ($esActivo) {
            $reglas['reason_code']        = ['required', Rule::in(self::MOTIVOS_SOBRE_ACTIVO)];
            // `accepted` exige true, "1", "on" o "yes": false no pasa.
            $reglas['acknowledge_active'] = ['required', 'accepted'];
        }

        $data = $request->validate($reglas, [
            'reason.required'             => 'El motivo del archivado es obligatorio.',
            'reason.min'                  => 'El motivo debe explicar la decisión: mínimo 10 caracteres.',
            'reason.max'                  => 'El motivo no puede pasar de 500 caracteres.',
            'confirm_ticket_id.required'  => 'Escribe el número del ticket para confirmar.',
            'reason_code.required'        => 'Un ticket abierto o en progreso sólo se archiva por duplicado o error de registro.',
            'reason_code.in'              => 'Un ticket abierto o en progreso sólo se archiva por duplicado o error de registro.',
            'acknowledge_active.required' => 'Confirma que entiendes que estás archivando un ticket con trabajo en curso.',
            'acknowledge_active.accepted' => 'Confirma que entiendes que estás archivando un ticket con trabajo en curso.',
        ]);

        // Comparación como cadena: el número llega del formulario como texto.
        if ((string) $data['confirm_ticket_id'] !== (string) $ticket->id) {
            return response()->json([
                'message' => 'El número de ticket que escribiste no coincide con el que vas a archivar.',
                'error'   => 'ticket_confirmation_mismatch',
            ], 422);
        }

        $factura = Invoice::where('ticket_id', $ticket->id)
            ->whereNotIn('status', self::FACTURAS_ANULADAS)
            ->first();

        if ($factura) {
            return response()->json([
                // La columna es `number`. NO `invoice_number`, que no existe en
                // `invoices` — ver P-46 en docs/MEJORAS_RECOMENDADAS.md, donde
                // queda anotado que `generateCharge()` sí la usa y por eso los
                // eventos `charge_created` nunca guardaron el número.
                'message' => 'Este ticket tiene un cargo sin anular y no puede archivarse. '
                    . 'Anula primero la factura ' . ($factura->number ?? "#{$factura->id}") . '.',
                'error'   => 'ticket_has_active_charge',
                'invoice' => [
                    'id'     => $factura->id,
                    'number' => $factura->number ?? null,
                    'status' => $factura->status,
                ],
            ], 422);
        }

        DB::transaction(function () use ($ticket, $data, $request, $esActivo) {
            // El evento va ANTES del archivado y dentro de la misma transacción:
            // si el archivado fallara, el historial no debe afirmar que ocurrió.
            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::ARCHIVED,
                metadata: array_filter([
                    'reason'      => $data['reason'],
                    'reason_code' => $data['reason_code'] ?? null,
                    // El estado en que quedó congelado el expediente. Restaurarlo
                    // lo devuelve ahí, y conviene que conste sin recalcularlo.
                    'status'      => $ticket->status,
                    'era_activo'  => $esActivo ?: null,
                ], fn ($v) => $v !== null),
            );

            // Asignación directa y no `fill()`: `archived_by` y `archived_reason`
            // NO están en `$fillable`, para que ningún `PUT` del formulario de
            // edición pueda escribirlos. Sólo se tocan por este camino.
            $ticket->archived_by     = $request->user()?->id;
            $ticket->archived_reason = $data['reason'];
            $ticket->save();

            // `delete()` sobre un modelo con SoftDeletes escribe `deleted_at`.
            // El guardia del modelo deja pasar esto y sigue bloqueando
            // `forceDelete()`.
            $ticket->delete();
        });

        return response()->json([
            'message' => 'Ticket archivado. El expediente se conserva íntegro y puede restaurarse. 🗄️',
            'ticket'  => SupportTicket::withTrashed()->with('archiver:id,user_name,user_lastname')->find($ticket->id),
        ]);
    }

    /**
     * Devuelve un expediente archivado a la operación.
     *
     * Exige motivo propio, como archivar. Restaurar también es una decisión
     * —alguien va a encontrarse de vuelta un ticket que creía retirado— y la
     * simetría evita el patrón habitual: mucha ceremonia para esconder y
     * ninguna para devolver.
     *
     * SIN LÍMITE DE TIEMPO. Si el archivado es reversible, un error deja de ser
     * una catástrofe; ponerle caducidad lo devolvería a serlo.
     */
    public function restore(Request $request, $id)
    {
        $ticket = SupportTicket::onlyTrashed()->findOrFail($id);

        $data = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ], [
            'reason.required' => 'El motivo de la restauración es obligatorio.',
            'reason.min'      => 'El motivo debe explicar la decisión: mínimo 10 caracteres.',
            'reason.max'      => 'El motivo no puede pasar de 500 caracteres.',
        ]);

        $motivoOriginal = $ticket->archived_reason;
        $archivadoEl    = $ticket->deleted_at?->toJSON();

        DB::transaction(function () use ($ticket, $data, $motivoOriginal, $archivadoEl) {
            $ticket->restore();

            // Se limpian para que la fila no siga afirmando que está archivada.
            // Nada se pierde: quién archivó, cuándo y por qué queda en el
            // historial, que es inalterable y por eso es la fuente buena.
            $ticket->archived_by     = null;
            $ticket->archived_reason = null;
            $ticket->save();

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::RESTORED,
                metadata: array_filter([
                    'reason'          => $data['reason'],
                    'archived_reason' => $motivoOriginal,
                    'archived_at'     => $archivadoEl,
                ], fn ($v) => $v !== null),
            );
        });

        return response()->json([
            'message' => 'Ticket restaurado. Vuelve a aparecer en la operación. ↩️',
            'ticket'  => $ticket->fresh(),
        ]);
    }
}
