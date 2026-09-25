<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
        
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando ticket...</p>
        </div>

        <div v-else class="max-w-7xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                        Ticket #{{ ticket.id }} - {{ ticket.subject }}
                    </h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">
                        Creado el {{ formatDate(ticket.created_at) }}
                    </p>
                    <span v-if="ticket.no_charge"
                        :title="ticket.no_charge_reason || 'Esta visita no se le cobra al cliente'"
                        class="mt-2 inline-block rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                        Sin cobro al cliente
                    </span>
                </div>
                <div class="flex gap-2">
                    <!-- PR C · Un expediente archivado está fuera de la operación:
                         el backend rechaza editarlo, anotarlo y transicionarlo, así
                         que la pantalla tampoco lo ofrece. -->
                    <button
                        v-if="canEdit && !ticket.is_archived"
                        @click="router.push(`/support/${ticketId}/edit`)"
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition"
                    >
                    <v-icon name="fa-edit" class="w-5 h-5 inline mr-2"></v-icon>
                        Editar
                    </button>
                    <button
                        v-if="canArchive && !ticket.is_archived"
                        @click="abrirArchivado"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg transition"
                    >
                        <v-icon name="bi-archive" class="w-5 h-5 inline mr-2"></v-icon>
                        Archivar
                    </button>
                    <button
                        v-if="canRestore && ticket.is_archived"
                        @click="abrirRestauracion"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition"
                    >
                        <v-icon name="ri-arrow-go-back-line" class="w-5 h-5 inline mr-2"></v-icon>
                        Restaurar
                    </button>
                    <button
                        @click="router.push('/support')"
                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition"
                    >
                    <v-icon name="ri-arrow-go-back-line" class="w-5 h-5 inline mr-2"></v-icon>
                        Volver
                    </button>
                </div>
            </div>

            <!-- PR C · El expediente archivado se ve entero, pero con el aviso
                 delante: quien lo abre tiene que saber que no está en operación
                 antes de leer nada, no descubrirlo al intentar anotar. -->
            <div
                v-if="ticket.is_archived"
                class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/30"
            >
                <div class="flex items-start gap-3">
                    <v-icon name="bi-archive" class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                    <div class="text-sm">
                        <p class="font-bold text-amber-900 dark:text-amber-200">
                            Expediente archivado el {{ formatDate(ticket.archived_at) }}
                        </p>
                        <p v-if="ticket.archived_reason" class="mt-1 text-amber-800 dark:text-amber-300">
                            <span class="font-semibold">Motivo:</span> {{ ticket.archived_reason }}
                        </p>
                        <p v-if="ticket.archiver" class="mt-1 text-amber-800 dark:text-amber-300">
                            <span class="font-semibold">Archivado por:</span>
                            {{ ticket.archiver.user_name }} {{ ticket.archiver.user_lastname }}
                        </p>
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                            El expediente se conserva íntegro —notas, adjuntos, cargos e historial— pero está
                            fuera de la operación: no admite ediciones ni notas hasta que se restaure.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Ciclo de vida (Solicitud Maestra §7, §15, §18) ──────────
                 Sustituye al selector libre de estado. Sólo se pintan los pasos
                 que el SERVIDOR dice que caben desde aquí: la matriz no se
                 duplica en JavaScript, porque una segunda copia se
                 desincroniza y el panel acaba ofreciendo lo que la API rechaza. -->
            <div
                v-if="!ticket.is_archived && (workflow.transitions.length || algunaAccion || workflow.isTerminal)"
                class="mb-6 rounded-xl bg-white p-6 shadow-md dark:bg-gray-800"
            >
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">Ciclo de vida</h3>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-gray-700 dark:text-slate-200">
                        {{ workflow.statusLabel || statusLabel(ticket.status) || ticket.status }}
                    </span>
                </div>

                <!-- Lo que le falta al expediente para poder cerrarse, ANTES de
                     abrir ningún modal: enterarse de que falta la causa
                     confirmada después de escribir el motivo es peor. -->
                <div
                    v-if="workflow.closureRequirements.missing.length"
                    class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/25"
                >
                    <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                        Para cerrar este ticket falta:
                    </p>
                    <ul class="mt-1 list-inside list-disc text-xs text-amber-800 dark:text-amber-300">
                        <li v-for="falta in workflow.closureRequirements.missing" :key="falta">{{ falta }}</li>
                    </ul>
                </div>

                <!--
                  Un ticket cerrado sin acciones disponibles NO se queda mudo.
                  Antes la tarjeta entera desaparecía y el operador no tenía
                  forma de saber si le faltaba un permiso, si el estado no lo
                  admitía o si la pantalla estaba rota. El motivo lo da el
                  servidor, que es el único que conoce los permisos.
                -->
                <div
                    v-if="!workflow.transitions.length && !algunaAccion"
                    class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-900/40"
                >
                    <p class="text-sm text-slate-700 dark:text-slate-200">
                        Este ticket está <strong>{{ workflow.statusLabel || statusLabel(ticket.status) }}</strong>
                        y no tiene acciones disponibles para ti.
                    </p>
                    <p v-if="workflow.reopenBlockedBy === 'permission'" class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        Reabrirlo exige el permiso <strong>«Tickets · reabrir»</strong>
                        (<code class="rounded bg-slate-200 px-1 dark:bg-gray-700">{{ workflow.reopenPermission }}</code>).
                        Pídeselo a quien administre los roles.
                    </p>
                    <p v-else-if="workflow.reopenBlockedBy === 'archived'" class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        Está archivado: restáuralo antes de volver a trabajarlo.
                    </p>
                </div>

                <!-- Transiciones ordinarias -->
                <div v-if="workflow.transitions.length" class="mb-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Mover a
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="paso in workflow.transitions"
                            :key="paso.code"
                            @click="abrirAccion('transition', paso)"
                            class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50"
                        >
                            {{ paso.label }}
                        </button>
                    </div>
                </div>

                <!-- Las cuatro operaciones con nombre propio -->
                <div v-if="algunaAccion" class="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <button
                        v-if="workflow.actions.propose_closure"
                        @click="abrirAccion('propose')"
                        class="flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300"
                    >
                        <v-icon name="md-assignmentturnedin" class="h-4 w-4" />
                        Proponer cierre
                    </button>
                    <button
                        v-if="workflow.actions.close"
                        @click="abrirAccion('close')"
                        class="flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300"
                    >
                        <v-icon name="bi-check-circle-fill" class="h-4 w-4" />
                        Cerrar ticket
                    </button>
                    <button
                        v-if="workflow.actions.close_exception"
                        @click="abrirAccion('exception')"
                        class="flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                    >
                        <v-icon name="md-warning" class="h-4 w-4" />
                        Cierre especial
                    </button>
                    <button
                        v-if="workflow.actions.reopen"
                        @click="abrirAccion('reopen')"
                        class="flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300"
                    >
                        <v-icon name="ri-arrow-go-back-line" class="h-4 w-4" />
                        Reabrir
                    </button>
                </div>
            </div>

            <!-- Badges -->
            <div class="flex gap-3 mb-6">
                <span :class="getStatusBadgeClass(ticket.status)">{{ getStatusLabel(ticket.status) }}</span>
                <span :class="getPriorityBadgeClass(ticket.priority)">{{ getPriorityLabel(ticket.priority) }}</span>
                <span :class="getCategoryBadgeClass(ticket.category)">{{ getCategoryLabel(ticket.category) }}</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna Principal -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Descripción -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Descripción</h2>
                        <p class="text-gray-600 dark:text-gray-300">{{ ticket.description || 'Sin descripción' }}</p>
                    </div>

                    <!-- Diagnóstico técnico (PR #2) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Diagnóstico técnico</h2>

                        <!-- Vacío: se dice que falta, no se esconde la sección.
                             Un ticket sin diagnosticar es información, no un hueco. -->
                        <p
                            v-if="!tieneDiagnostico"
                            class="text-gray-500 dark:text-gray-400 text-sm"
                        >
                            Sin diagnóstico registrado todavía.
                            <router-link
                                v-if="canEdit"
                                :to="`/support/${ticket.id}/edit`"
                                class="text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Registrarlo
                            </router-link>
                        </p>

                        <dl v-else class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
                            <div v-for="campo in camposDeDiagnostico" :key="campo.clave">
                                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ campo.etiqueta }}
                                </dt>
                                <dd class="mt-1 text-gray-800 dark:text-gray-100">
                                    <template v-if="campo.valor">
                                        <span class="inline-flex items-center gap-2">
                                            <code class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-xs font-mono">
                                                {{ campo.valor.code }}
                                            </code>
                                            <span>{{ campo.valor.label }}</span>
                                        </span>
                                    </template>
                                    <span v-else class="text-gray-400 dark:text-gray-500 italic">Sin definir</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Intervenciones tecnicas (PR F1 - seccion 14) -->
                    <TicketInterventions
                        ref="interventionsRef"
                        :ticket-id="ticketId"
                        :puede-intervenir="canIntervene"
                        :ticket-archivado="!!ticket.is_archived"
                        :tecnicos="staffList"
                        @cambio="loadTicket"
                        @cargadas="interventionsForSelect = $event"
                    />

                    <!-- Pruebas tecnicas (PR F2 - secciones 12 y 13) -->
                    <TicketMeasurements
                        ref="measurementsRef"
                        :ticket-id="ticketId"
                        :puede-medir="canIntervene"
                        :bloqueado="!!ticket.is_archived || workflow.isTerminal"
                        :intervenciones="interventionsForSelect"
                        @cambio="alCambiarMedicion"
                    />

                    <!-- Historial inalterable (PR #3 · F1-17) -->
                    <div v-if="canViewHistory" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-1">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Historial</h2>
                            <span class="text-xs text-gray-400 dark:text-gray-500">Registro no editable</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            Horas en Colombia (America/Bogota).
                        </p>

                        <div v-if="historialCargando && !historial.length" class="flex items-center gap-3 py-4 text-gray-500 dark:text-gray-400">
                            <div class="animate-spin rounded-full h-5 w-5 border-2 border-blue-500 border-t-transparent"></div>
                            <span class="text-sm">Cargando historial…</span>
                        </div>

                        <p v-else-if="historialError" class="text-sm text-amber-700 dark:text-amber-300">
                            No se pudo cargar el historial.
                            <button type="button" @click="cargarHistorial(1)" class="underline font-medium">Reintentar</button>
                        </p>

                        <p v-else-if="!historial.length" class="text-sm text-gray-500 dark:text-gray-400">
                            Todavía no hay movimientos registrados en este ticket.
                        </p>

                        <ol v-else class="relative border-l border-gray-200 dark:border-gray-700 ml-2 space-y-5">
                            <li v-for="evento in historial" :key="evento.id" class="ml-5">
                                <span class="absolute -left-[5px] w-2.5 h-2.5 rounded-full bg-blue-500"></span>

                                <p class="text-sm text-gray-800 dark:text-gray-100">
                                    {{ etiquetaDeEvento(evento) }}
                                </p>

                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ actorDe(evento) }} · {{ formatDateBogota(evento.created_at) }}
                                </p>
                            </li>
                        </ol>

                        <button
                            v-if="historialHayMas"
                            type="button"
                            @click="cargarHistorial(historialPagina + 1)"
                            :disabled="historialCargando"
                            class="mt-5 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50"
                        >
                            {{ historialCargando ? 'Cargando…' : 'Ver movimientos anteriores' }}
                        </button>
                    </div>

                    <!-- Bitácora de Trabajo (Notas del Staff) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Bitácora de Trabajo</h2>
                            <button 
                                v-if="canNote"
                                @click="showNoteForm = true"
                                class="text-sm bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg transition flex items-center gap-1"
                            >
                                <v-icon name="md-add" class="w-4 h-4" />
                                Nueva Nota
                            </button>
                        </div>

                        <!-- Formulario para Nueva Nota -->
                        <div v-if="showNoteForm" class="mb-6 bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg border border-gray-200 dark:border-gray-600">
                            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">
                                {{ editingNoteId ? 'Editar Nota' : 'Agregar Nueva Nota' }}
                            </h3>
                            <textarea 
                                v-model="noteContent"
                                rows="3"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 mb-3"
                                placeholder="Escribe aquí los detalles del trabajo realizado..."
                            ></textarea>
                            <div class="flex justify-end gap-2 text-sm">
                                <button 
                                    @click="cancelNote"
                                    class="px-3 py-1 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 rounded"
                                >
                                    Cancelar
                                </button>
                                <button 
                                    @click="saveNote"
                                    :disabled="!noteContent.trim() || savingNote"
                                    class="px-4 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition disabled:opacity-50"
                                >
                                    {{ savingNote ? 'Guardando...' : 'Guardar' }}
                                </button>
                            </div>
                        </div>

                        <!-- Lista de Notas -->
                        <div v-if="ticket.messages?.length > 0" class="space-y-4">
                            <div v-for="message in ticket.messages" :key="message.id" 
                                 class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-100 dark:border-gray-700 relative group">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-xs">
                                            {{ (message.author_label || '?').charAt(0) }}
                                        </div>
                                        <div>
                                            <!-- `author_label` lo resuelve el backend: usuario vivo,
                                                 si no el nombre congelado al escribir, si no
                                                 «Usuario eliminado». Nunca se accede a
                                                 `user.user_name` directamente, porque `user_id`
                                                 puede ser NULL desde que dar de baja a un cliente
                                                 dejó de borrar sus notas (H-6). -->
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ message.author_label }}</p>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ formatDate(message.created_at) }}</p>
                                        </div>
                                    </div>
                                    <div v-if="canNote" class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button @click="editNote(message)" class="p-1 text-gray-400 hover:text-blue-500 transition-colors">
                                            <v-icon name="fa-edit" class="w-4 h-4" />
                                        </button>
                                        <button @click="deleteNote(message.id)" class="p-1 text-gray-400 hover:text-red-500 transition-colors">
                                            <v-icon name="md-delete" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-wrap">{{ message.message }}</p>
                            </div>
                        </div>
                        <div v-else class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm italic">
                            No hay notas registradas en este ticket.
                        </div>
                    </div>

                    <!-- Archivos Adjuntos -->
                    <div v-if="ticket.attachments?.length > 0" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Archivos Adjuntos</h2>
                        <div class="space-y-2">
                            <div v-for="attachment in ticket.attachments" :key="attachment.id"
                                 class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <v-icon name="bi-file-earmark" class="w-5 h-5 text-gray-500" />
                                    <div>
                                        <span class="text-sm text-gray-800 dark:text-white">{{ attachment.file_name }}</span>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                            Subido por {{ attachment.author_label }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button 
                                        v-if="isImage(attachment.file_name)"
                                        @click="openImage(attachment)"
                                        class="text-blue-600 hover:text-blue-700 text-sm flex items-center gap-1"
                                    >
                                        <v-icon name="fa-eye" class="w-4 h-4" />
                                        Ver
                                    </button>
                                    <!-- `download_url` fuerza Content-Disposition:
                                         attachment en el servidor. El atributo
                                         `download` de HTML no basta: sólo se
                                         respeta en el mismo origen y el navegador
                                         acabaría abriendo la imagen en la pestaña. -->
                                    <a :href="attachment.download_url" target="_blank"
                                       class="text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-white text-sm flex items-center gap-1">
                                        <v-icon name="md-download" class="w-4 h-4" />
                                        Descargar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal de Visualización de Imagen (Estilo Manual) -->
                    <Teleport to="body">
                        <div 
                          v-if="lightboxImage" 
                          class="fixed inset-0 z-app-modal flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
                          @click="lightboxImage = null"
                        >
                          <div 
                            class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-5xl w-full mx-4 overflow-hidden transform transition-all border border-gray-100 dark:border-gray-700 mx-auto"
                            @click.stop
                          >
                            <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-start bg-indigo-50/50 dark:bg-gray-700/30">
                              <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Vista Previa</h3>
                                 <span class="text-xs text-indigo-500 dark:text-indigo-300 uppercase font-semibold tracking-wider mt-1 block">Imagen Adjunta</span>
                              </div>
                              <button 
                                @click="lightboxImage = null"
                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600"
                              >
                                <v-icon name="io-close" class="w-6 h-6" />
                              </button>
                            </div>
                            
                            <div class="p-0 bg-gray-50 dark:bg-gray-900/50 flex justify-center items-center min-h-[8rem]">
                                <!-- Si el archivo ya no está en el almacenamiento, el
                                     endpoint responde 404 y el <img> mostraría el icono
                                     roto sin decir por qué. Se sustituye por un aviso. -->
                                <div v-if="lightboxError" class="p-8 text-center">
                                    <p class="text-gray-700 dark:text-gray-200 font-medium">
                                        No se pudo cargar la vista previa.
                                    </p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                        El archivo ya no está disponible en el almacenamiento.
                                    </p>
                                </div>
                                <img
                                    v-else
                                    :src="lightboxImage"
                                    @error="lightboxError = true"
                                    class="max-w-full max-h-[80vh] rounded-none shadow-sm"
                                    alt="Vista previa"
                                />
                            </div>

                            <div class="p-6 border-t border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 flex justify-end gap-3">
                               <a
                                v-if="!lightboxError"
                                :href="lightboxDownload"
                                target="_blank"
                                class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 font-medium text-sm transition-colors flex items-center gap-2"
                              >
                                <v-icon name="md-download" class="w-4 h-4" />
                                Descargar
                              </a>
                              <button 
                                @click="lightboxImage = null"
                                class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium text-sm transition-colors"
                              >
                                Cerrar
                              </button>
                            </div>
                          </div>
                        </div>
                    </Teleport>
                   
                   <!-- Equipos y materiales de la visita.
                        Descuentan del inventario de quien los aporta y quedan
                        en el kardex: cargar un equipo aquí es sacarlo de la
                        bodega de verdad, no anotarlo. -->
                    <div v-if="puedeEquipos" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-1">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Equipos de la visita</h2>
                            <span v-if="equipmentItems.length" class="text-xs text-blue-600 dark:text-blue-400">
                                {{ equipmentItems.length }} línea(s) · {{ formatCurrency(equipmentTotal) }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            Lo que se le entrega al cliente y lo que se le retira. Cada línea mueve el inventario
                            de verdad y queda en el historial del equipo.
                        </p>

                        <ul v-if="equipmentItems.length" class="space-y-2 mb-4">
                            <li v-for="item in equipmentItems" :key="item.id"
                                class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border"
                                :class="item.is_reversed
                                    ? 'bg-gray-50 dark:bg-gray-800/40 border-dashed border-gray-300 dark:border-gray-600 opacity-70'
                                    : (item.is_return
                                        ? 'bg-amber-50 dark:bg-amber-900/10 border-amber-200 dark:border-amber-900/40'
                                        : 'bg-gray-50 dark:bg-gray-700/30 border-gray-200 dark:border-gray-700')">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium truncate"
                                       :class="item.is_reversed ? 'text-gray-500 dark:text-gray-400 line-through' : 'text-gray-800 dark:text-white'">
                                        <span :class="item.is_return ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
                                            {{ item.is_return ? '←' : '→' }}
                                        </span>
                                        {{ item.label }}
                                    </p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                        {{ item.is_return ? (item.is_scrapped ? 'Retirado y dado de baja' : 'Retirado del cliente') : (item.is_device ? 'Equipo entregado' : 'Material usado') }}
                                        <template v-if="!item.is_device"> · {{ item.quantity }}{{ item.unit ? ' ' + item.unit : '' }}</template>
                                        <template v-if="item.unit_price != null"> · {{ formatCurrency(item.unit_price * item.quantity) }}</template>
                                    </p>
                                    <!-- La linea revertida NO se oculta: el expediente tiene que contar
                                         que el aparato se movio, y quien lo deshizo, cuando y por que. -->
                                    <p v-if="item.is_reversed" class="mt-1 text-[11px] text-red-500 dark:text-red-400">
                                        Deshecho{{ item.reversed_by_name ? ' por ' + item.reversed_by_name : '' }}{{ item.reversed_at ? ' · ' + formatDate(item.reversed_at) : '' }}
                                        <template v-if="item.reversal_reason"> · «{{ item.reversal_reason }}»</template>
                                    </p>
                                </div>
                                <button v-if="puedeEquipos && !item.is_reversed" @click="removeEquipment(item)" :disabled="equipmentBusy" type="button"
                                    :title="item.is_return ? 'Deshacer el retiro' : 'Devolver al inventario'"
                                    class="shrink-0 p-1.5 rounded-md text-red-400 hover:text-red-600 hover:bg-red-100 dark:hover:bg-red-900/30 transition disabled:opacity-50">
                                    <v-icon name="md-delete" class="w-4 h-4" />
                                </button>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 italic mb-4">
                            Todavía no se ha movido ningún equipo en este ticket.
                        </p>

                        <div v-if="puedeEquipos && !ticket.archived_at" class="space-y-3 border-t border-gray-100 dark:border-gray-700 pt-4">
                            <!-- Entregar un equipo con serial -->
                            <div>
                                <select v-model.number="devicePick" @change="addDevice" :disabled="equipmentBusy"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50">
                                    <option :value="null">+ Entregar equipo con serial…</option>
                                    <optgroup v-for="grupo in devicesByHolder" :key="grupo.label" :label="grupo.label">
                                        <option v-for="d in grupo.items" :key="d.id" :value="d.id">{{ deviceLabel(d) }}</option>
                                    </optgroup>
                                </select>
                                <p v-if="equipmentLoaded && !availableDevices.length" class="mt-1 text-xs text-blue-600 dark:text-blue-400">
                                    No tienes equipos con serial disponibles. Pide que te los entreguen en Inventario → Entregas.
                                </p>
                            </div>

                            <!-- Retirar un equipo que el cliente ya tiene -->
                            <div v-if="installedDevices.length" class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto] gap-2">
                                <select v-model.number="returnPick" :disabled="equipmentBusy"
                                    class="px-3 py-2 text-sm rounded-lg border border-amber-300 dark:border-amber-800 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50">
                                    <option :value="null">− Retirar equipo del cliente…</option>
                                    <option v-for="d in installedDevices" :key="d.id" :value="d.id">{{ deviceLabel(d) }}</option>
                                </select>
                                <select v-model="returnTarget" :disabled="equipmentBusy"
                                    class="px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50">
                                    <option v-for="s in returnTargets" :key="`${s.type}-${s.id}`" :value="`${s.type}:${s.id ?? ''}`">
                                        {{ s.label }}
                                    </option>
                                </select>
                                <button @click="retireDevice" type="button" :disabled="!returnPick || equipmentBusy"
                                    class="px-4 py-2 text-sm text-white rounded-lg transition disabled:opacity-50"
                                    :class="vaADarDeBaja ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'">
                                    {{ vaADarDeBaja ? 'Retirar y dar de baja' : 'Retirar' }}
                                </button>
                                <p v-if="vaADarDeBaja" class="sm:col-span-3 text-xs text-red-600 dark:text-red-400">
                                    El equipo saldrá del inventario como dado de baja y no volverá a aparecer como
                                    disponible. Úsalo sólo si volvió inservible.
                                </p>
                            </div>

                            <!-- Materiales -->
                            <div v-if="availableMaterials.length" class="grid grid-cols-1 sm:grid-cols-[1fr_6rem_auto] gap-2">
                                <select v-model="materialPick" :disabled="equipmentBusy"
                                    class="px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50">
                                    <option :value="null">+ Agregar material…</option>
                                    <option v-for="m in availableMaterials" :key="`${m.stock_id}-${m.source_type}-${m.source_id}`" :value="m">
                                        {{ materialLabel(m) }}
                                    </option>
                                </select>
                                <input v-model.number="materialQty" type="number" min="0.01" step="0.01" onwheel="this.blur()"
                                    :disabled="equipmentBusy"
                                    class="charge-num px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50" />
                                <button @click="addMaterial" type="button" :disabled="!materialPick || equipmentBusy"
                                    class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50">
                                    Agregar
                                </button>
                            </div>
                        </div>

                        <p v-if="puedeEquipos && !ticket.archived_at" class="mt-3 text-[11px] text-gray-400 dark:text-gray-500">
                            El equipo entregado queda a nombre del cliente y el retirado vuelve al inventario.
                            Cobrarlo es aparte: usa «Cobrar equipo del ticket» en Cargos.
                        </p>
                    </div>

                   <!-- Cargos del Ticket (Staff Only) -->
                    <div v-if="canEdit" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Cargos del Ticket</h2>
                            <button
                                v-if="!ticket.no_charge"
                                @click="showChargeForm = !showChargeForm"
                                class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-lg flex items-center gap-1 transition"
                            >
                                <v-icon name="md-add" class="w-4 h-4" />
                                Nuevo Cargo
                            </button>
                        </div>

                        <!-- Ticket sin cobro: no se esconde el bloque, se explica.
                             Esconderlo dejaría a quien busca el botón pensando que
                             le falta un permiso. -->
                        <div v-if="ticket.no_charge"
                            class="mb-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
                            Este ticket está marcado <strong>sin cobro al cliente</strong>, así que no admite cargos.
                            <span v-if="ticket.no_charge_reason">Motivo: {{ ticket.no_charge_reason }}.</span>
                            Si finalmente hay que cobrarlo, quita la marca en <em>Editar ticket</em>.
                        </div>

                        <!-- Formulario nuevo cargo -->
                        <div v-if="showChargeForm && !ticket.no_charge" class="mb-6 bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg border border-gray-200 dark:border-gray-600 space-y-4">
                            <div class="flex items-center gap-2 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm">
                                <v-icon name="bi-person" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                <span class="text-gray-600 dark:text-gray-300">Se cobrará a:</span>
                                <strong class="text-gray-800 dark:text-white">{{ nombreDelCliente }}</strong>
                            </div>
                            <div class="space-y-3">
                                <datalist id="ticket-charge-unit-options">
                                    <option v-for="u in unitOptions" :key="u" :value="u" />
                                </datalist>
                                <div v-for="(item, idx) in chargeForm.items" :key="idx" class="grid grid-cols-2 sm:grid-cols-3 gap-3 p-3 bg-white dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="col-span-2 sm:col-span-3">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Descripción</label>
                                        <input
                                            v-model="item.description"
                                            type="text"
                                            placeholder="Ej: Cambio de antena, Cable UTP, Visita técnica…"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cantidad</label>
                                        <input
                                            v-model.number="item.quantity"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            onwheel="this.blur()"
                                            class="charge-num w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unidad</label>
                                        <input
                                            v-model.trim="item.unit"
                                            list="ticket-charge-unit-options"
                                            type="text"
                                            placeholder="Unidad"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div class="col-span-2 sm:col-span-1">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Precio unitario</label>
                                        <input
                                            v-model.number="item.unit_price"
                                            type="number"
                                            min="0"
                                            step="1"
                                            onwheel="this.blur()"
                                            class="charge-num w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div class="flex items-center justify-between col-span-2 sm:col-span-3">
                                        <span class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ formatQuantity(item) }} × {{ formatCurrency(item.unit_price) }} —
                                            Subtotal: <strong>{{ formatCurrency(item.quantity * item.unit_price) }}</strong>
                                        </span>
                                        <button v-if="chargeForm.items.length > 1" @click="removeChargeItem(idx)" class="text-red-500 hover:text-red-700">
                                            <v-icon name="md-delete" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button @click="addChargeItem" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 flex items-center gap-1">
                                    <v-icon name="md-add" class="w-4 h-4" />
                                    Agregar ítem
                                </button>

                                <!-- El equipo ya salió del inventario; esto sólo
                                     trae su descripción y su precio al cargo para
                                     no volver a teclearlos. Lo que se cobra sigue
                                     decidiéndolo quien factura: la línea entra
                                     editable como cualquier otra. Los retiros no
                                     aparecen — no se cobra lo que se recogió. -->
                                <select v-if="cobrables.length" v-model.number="chargePick" @change="addChargeFromEquipment"
                                    class="text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2 py-1.5 text-gray-700 dark:text-gray-200">
                                    <option :value="null">+ Cobrar equipo del ticket</option>
                                    <option v-for="it in cobrables" :key="it.id" :value="it.id">
                                        {{ it.label }}{{ it.unit_price != null ? ` — ${formatCurrency(it.unit_price * it.quantity)}` : '' }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Fecha de vencimiento (opcional)</label>
                                <input
                                    v-model="chargeForm.due_date"
                                    type="date"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notas (opcional)</label>
                                <textarea
                                    v-model="chargeForm.notes"
                                    rows="2"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                ></textarea>
                            </div>

                            <div class="flex justify-between items-center border-t border-gray-200 dark:border-gray-600 pt-3">
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                    Total: <span class="text-lg text-green-600 dark:text-green-400">{{ formatCurrency(chargeTotal) }}</span>
                                </span>
                                <div class="flex gap-2">
                                    <button @click="cancelCharge" class="px-3 py-1 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 rounded">Cancelar</button>
                                    <button
                                        @click="submitCharge"
                                        :disabled="submittingCharge || !isChargeFormValid"
                                        class="px-4 py-1 text-sm bg-green-600 text-white rounded hover:bg-green-700 transition disabled:opacity-50"
                                    >
                                        {{ submittingCharge ? 'Generando...' : 'Generar Cargo' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Lista de cargos existentes -->
                        <div v-if="loadingCharges" class="text-center py-4 text-sm text-gray-500 dark:text-gray-400">Cargando cargos...</div>
                        <div v-else-if="ticketCharges.length > 0" class="space-y-3">
                            <div v-for="charge in ticketCharges" :key="charge.id"
                                class="p-4 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-100 dark:border-gray-700 flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white">Factura #{{ charge.number }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatDate(charge.created_at) }}</p>
                                    <p v-if="charge.notes" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ charge.notes }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold text-green-600 dark:text-green-400">{{ formatCurrency(charge.total) }}</p>
                                    <span :class="chargeStatusClass(charge.status)" class="text-xs px-2 py-0.5 rounded-full font-medium">{{ chargeStatusLabel(charge.status) }}</span>
                                    <a :href="`/billing/invoices/${charge.id}`" class="block text-xs text-blue-600 dark:text-blue-400 hover:underline mt-1">Ver factura</a>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-4 text-sm text-gray-500 dark:text-gray-400 italic">
                            No hay cargos registrados en este ticket.
                        </div>
                    </div>

                   <!-- Gestión del Ticket: subir evidencia. Exige `ticket_attach`,
                        no `ticket_edit`: adjuntar es una capacidad propia. -->
                    <div v-if="canAttach" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                         <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Gestión del Ticket</h2>
                         
                         <div class="space-y-4">
                            <!-- Subir Imágenes de Trabajo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Evidencia de Trabajo (Imágenes)</label>
                                <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4 text-center">
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        multiple
                                        accept="image/*"
                                        @change="handleFileChange"
                                        class="hidden"
                                    />
                                    <button
                                        type="button"
                                        @click="fileInput.click()"
                                        class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                                    >
                                        <v-icon name="bi-images" class="w-5 h-5 inline mr-2" />
                                        Seleccionar Imágenes
                                    </button>
                                </div>

                                <!-- Previsualización -->
                                <div v-if="selectedFiles.length > 0" class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div v-for="(file, index) in selectedFiles" :key="index" class="relative group">
                                        <img :src="file.preview" class="w-full h-24 object-cover rounded-lg border border-gray-200 dark:border-gray-700" />
                                        <button 
                                            @click="removeFile(index)"
                                            class="absolute top-1 right-1 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition"
                                        >
                                            <v-icon name="io-close" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button
                                @click="updateTicket"
                                :disabled="updating"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition disabled:opacity-50"
                            >
                                <span v-if="!updating">Actualizar Ticket</span>
                                <span v-else>Guardando...</span>
                            </button>
                         </div>
                    </div>
                </div>

                <!-- Columna Lateral -->
                <div class="space-y-6">
                    <!-- Info del Cliente -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Cliente</h3>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Nombre:</span> {{ nombreDelCliente }}
                            </p>
                            <!-- Correo y teléfono sólo si el cliente sigue existiendo. Un
                                 ticket cuyo cliente se dio de baja conserva el expediente,
                                 pero ya no tiene ficha de contacto que mostrar. -->
                            <template v-if="ticket.user">
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold">Email:</span> {{ ticket.user.email }}
                                </p>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold">Teléfono:</span> {{ ticket.user.tel }}
                                </p>
                            </template>
                        </div>
                    </div>
                    <!-- Staff asignado  -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Técnico asignado</h3>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Nombre:</span> {{ ticket.staff?.user_name || "No asignado"}}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <!-- ── PR C · Archivar expediente ──────────────────────────────────
             Doble confirmación: motivo escrito Y el número del ticket tecleado.
             Las dos se validan TAMBIÉN en el servidor; esto es la puerta, no la
             cerradura. Un `confirm()` se acepta por reflejo — escribir el número
             obliga a mirar cuál es. -->
        <Teleport to="body">
            <div
                v-if="modalArchivar"
                class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
                @click="cerrarArchivado"
            >
                <div
                    class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-100 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
                    @click.stop
                >
                    <div class="border-b border-gray-100 bg-amber-50/60 p-6 dark:border-gray-700 dark:bg-gray-700/30">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            Archivar el ticket #{{ ticket.id }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            El expediente se conserva íntegro y puede restaurarse en cualquier momento.
                            No se borra ninguna nota, adjunto ni cargo.
                        </p>
                    </div>

                    <div class="space-y-4 p-6">
                        <!-- Trabajo vivo: dos barreras más, porque archivar un
                             ticket en curso casi siempre es un error. -->
                        <div
                            v-if="ticketActivo"
                            class="rounded-lg border border-red-300 bg-red-50 p-3 dark:border-red-700 dark:bg-red-900/30"
                        >
                            <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                                Este ticket está {{ statusLabel(ticket.status) }}: tiene trabajo en curso.
                            </p>
                            <p class="mt-1 text-xs text-red-700 dark:text-red-400">
                                Un ticket activo sólo se archiva si es un duplicado o un error de registro.
                                Si el trabajo simplemente terminó, <strong>ciérralo</strong> en vez de archivarlo:
                                así sigue contando en las estadísticas.
                            </p>

                            <label class="mt-3 block text-xs font-semibold text-red-800 dark:text-red-300">
                                Razón
                            </label>
                            <select
                                v-model="formArchivo.reason_code"
                                class="mt-1 w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm dark:border-red-700 dark:bg-gray-900 dark:text-gray-100"
                            >
                                <option value="">Selecciona…</option>
                                <option value="duplicate">Es un duplicado de otro ticket</option>
                                <option value="registration_error">Se abrió por error de registro</option>
                            </select>

                            <label class="mt-3 flex items-start gap-2 text-xs text-red-800 dark:text-red-300">
                                <input
                                    v-model="formArchivo.acknowledge_active"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-red-400"
                                />
                                <span>Entiendo que estoy archivando un ticket con trabajo en curso.</span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                                Motivo del archivado <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="formArchivo.reason"
                                rows="3"
                                maxlength="500"
                                placeholder="Explica por qué se retira este expediente de la operación…"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                            ></textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ formArchivo.reason.length }}/500 · mínimo 10 caracteres.
                                Queda registrado en el historial con tu nombre.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                                Escribe <span class="font-mono text-amber-600 dark:text-amber-400">{{ ticket.id }}</span>
                                para confirmar <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="formArchivo.confirm_ticket_id"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                :placeholder="`Número del ticket (${ticket.id})`"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <button
                            @click="cerrarArchivado"
                            class="rounded-lg px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            Cancelar
                        </button>
                        <button
                            :disabled="!archivadoListo || enviandoArchivo"
                            @click="archivarTicket"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ enviandoArchivo ? 'Archivando…' : 'Archivar expediente' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ── PR C · Restaurar expediente ─────────────────────────────────
             Con motivo propio, igual que archivar. Alguien va a encontrarse de
             vuelta un ticket que creía retirado y debe constar por qué. -->
        <Teleport to="body">
            <div
                v-if="modalRestaurar"
                class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
                @click="cerrarRestauracion"
            >
                <div
                    class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-100 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
                    @click.stop
                >
                    <div class="border-b border-gray-100 bg-blue-50/60 p-6 dark:border-gray-700 dark:bg-gray-700/30">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            Restaurar el ticket #{{ ticket.id }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Vuelve a la operación con el estado que tenía al archivarse.
                        </p>
                    </div>

                    <div class="p-6">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Motivo de la restauración <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="formRestauracion.reason"
                            rows="3"
                            maxlength="500"
                            placeholder="Explica por qué vuelve a la operación…"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ formRestauracion.reason.length }}/500 · mínimo 10 caracteres.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <button
                            @click="cerrarRestauracion"
                            class="rounded-lg px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            Cancelar
                        </button>
                        <button
                            :disabled="formRestauracion.reason.trim().length < 10 || enviandoRestauracion"
                            @click="restaurarTicket"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ enviandoRestauracion ? 'Restaurando…' : 'Restaurar expediente' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ── Modal único de las acciones del ciclo de vida ───────────────
             Uno solo y no cinco: las cinco piden lo mismo —una confirmación y,
             según el caso, un motivo— y cinco copias del mismo formulario se
             desincronizan a la primera corrección. Lo que cambia entre ellas
             está en `ACCIONES`. -->
        <Teleport to="body">
            <div
                v-if="accion"
                class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
                @click="cerrarAccion"
            >
                <div
                    class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-100 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
                    @click.stop
                >
                    <div class="border-b border-gray-100 p-6 dark:border-gray-700" :class="accion.cabecera">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">{{ accion.titulo }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ accion.descripcion }}</p>
                    </div>

                    <div class="space-y-4 p-6">
                        <!-- El cierre especial deja constancia de QUÉ faltó: se
                             enseña aquí para que quien autoriza sepa qué firma. -->
                        <div
                            v-if="accion.clave === 'exception' && workflow.closureRequirements.missing.length"
                            class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/25"
                        >
                            <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">
                                Vas a autorizar el cierre sin:
                            </p>
                            <ul class="mt-1 list-inside list-disc text-xs text-amber-800 dark:text-amber-300">
                                <li v-for="falta in workflow.closureRequirements.missing" :key="falta">{{ falta }}</li>
                            </ul>
                        </div>

                        <!-- PR F2 - regla 5 del parrafo 15: prueba final O
                             justificacion. Solo aparece cuando de verdad falta,
                             y solo en propuesta y cierre ordinario: el cierre
                             excepcional ya documenta el requisito incumplido por
                             otra via. -->
                        <div
                            v-if="pideJustificacionDePruebaFinal"
                            class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/25"
                        >
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                Este ticket no tiene medición final. Indica por qué no fue posible tomarla.
                            </p>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                                    Razón <span class="text-red-500">*</span>
                                </label>
                                <select
                                    v-model="exencionPruebaFinal.reason"
                                    class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                >
                                    <option :value="null">— Seleccionar —</option>
                                    <option v-for="r in finalTestWaiverReasons" :key="r.code" :value="r.code">
                                        {{ r.label }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-200">
                                    Justificación <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    v-model="exencionPruebaFinal.note"
                                    rows="2"
                                    placeholder="Entre 10 y 500 caracteres"
                                    class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                ></textarea>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                                {{ accion.etiquetaMotivo }}
                                <span v-if="accion.motivoObligatorio" class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="motivoAccion"
                                rows="3"
                                maxlength="500"
                                :placeholder="accion.placeholder"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                            ></textarea>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ motivoAccion.length }}/500
                                <template v-if="accion.motivoObligatorio"> · mínimo 10 caracteres</template>
                                · queda en el historial con tu nombre.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <button
                            @click="cerrarAccion"
                            class="rounded-lg px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-200 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            Cancelar
                        </button>
                        <button
                            :disabled="!accionLista || ejecutandoAccion"
                            @click="ejecutarAccion"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-40"
                            :class="accion.boton"
                        >
                            {{ ejecutandoAccion ? 'Procesando…' : accion.textoBoton }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import NotificationToast from '../components/NotificationToast.vue'
import TicketInterventions from '../components/TicketInterventions.vue'
import TicketMeasurements from '../components/TicketMeasurements.vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import ticketEquipmentApi from '@/services/api/ticket-equipment'

// R2: las ETIQUETAS vienen del catálogo; los COLORES se quedan abajo porque
// se deciden por código —que es estable— y son presentación.
const {
    statuses,
    cargar: cargarCatalogos,
    statusLabel, priorityLabel, categoryLabel,
    // PR F2 - lista CERRADA de razones para cerrar sin medicion final (§ 13).
    finalTestWaiverReasons,
} = useTicketCatalogs()

const router = useRouter()
const route = useRoute()
const ticketId = route.params.id

const authStore = useAuthStore()
// PR B · `canEdit` gobierna el botón «Editar» y las acciones de la bitácora,
// que son capacidades distintas: editar el ticket y anotar en él.
const canEdit = computed(() => authStore.hasPermission('ticket_edit'))
const canNote = computed(() => authStore.hasPermission('ticket_note'))
const canViewHistory = computed(() => authStore.hasPermission('ticket_view_history'))
const canAttach = computed(() => authStore.hasPermission('ticket_attach'))
// PR F1 - registrar intervenciones tecnicas (seccion 14).
const canIntervene = computed(() => authStore.hasPermission('ticket_intervene'))

// Personal del tenant para los desplegables de tecnico y acompanante. El
// backend valida ademas que el id pertenezca al tenant: esta lista es comodidad
// de la interfaz, nunca la autorizacion.
const staffList = ref([])
const interventionsRef = ref(null)
const measurementsRef = ref(null)

// Visitas del ticket, para que una medicion pueda colgar de una. Se llenan desde
// el componente de intervenciones al cargar; si aun no hay, el desplegable sale
// vacio y la medicion se guarda sin intervencion, que es un caso valido.
const interventionsForSelect = ref([])

/**
 * Una medicion nueva puede cambiar si el ticket ya se puede cerrar: la regla 5
 * del parrafo 15 exige prueba final o justificacion. Por eso se recalcula el
 * workflow, no solo se repinta la lista.
 */
// PR F2 - lo que se manda al cerrar cuando no hubo medicion final.
const exencionPruebaFinal = ref({ reason: null, note: '' })

const alCambiarMedicion = async () => {
    await cargarWorkflow()
}

const loadStaff = async () => {
    try {
        const { data } = await api.staff.getAll()
        staffList.value = data.data || []
    } catch (e) {
        // Sin lista, los desplegables quedan vacios pero el resto de la pantalla
        // sigue funcionando. No se rompe el detalle del ticket por esto.
        staffList.value = []
    }
}
// PR C · Archivar y restaurar. CNO los aprobó para «Administradores y
// Propietarios»; en ISPWatch «Propietario» no existe como rol, así que la
// migración los concede a `code = 'admin'` y el superadministrador pasa por su
// propio bypass. Ver DISENO_PERMISOS_Y_ARCHIVADO.md §0 (supuesto S-1).
const canArchive = computed(() => authStore.hasPermission('ticket_archive'))
const canRestore = computed(() => authStore.hasPermission('ticket_restore'))

// Si un rol conserva el `view_support` antiguo pero le faltan los granulares
// —backfill no ejecutado, o un rol creado a mano después— la pantalla NO eleva
// el privilegio: oculta la acción y deja constancia en consola para que el
// operador pueda reportarlo. Ver DISENO_PERMISOS_Y_ARCHIVADO.md §6bis.
if (authStore.hasPermission('view_support') && !authStore.hasPermission('ticket_view')) {
    console.warn(
        '[permisos] Este rol tiene `view_support` pero no `ticket_view`. ' +
        'Las acciones de ticket se ocultan por seguridad. ' +
        'Falta ejecutar la migración de transición de permisos (PR B).'
    )
}

const ticket = ref({})

// ── Diagnóstico técnico (PR #2) ──
//
// El backend ya envía código Y etiqueta en `diagnosis`, así que esta pantalla no
// necesita el catálogo: para MOSTRAR basta lo que trae el ticket. Sólo la
// pantalla de edición carga los catálogos, porque es la única que ofrece a elegir.
const camposDeDiagnostico = computed(() => {
    const d = ticket.value.diagnosis ?? {}

    return [
        { clave: 'symptom', etiqueta: 'Síntoma reportado', valor: d.symptom },
        { clave: 'suspected_cause', etiqueta: 'Causa sospechada', valor: d.suspected_cause },
        { clave: 'confirmed_cause', etiqueta: 'Causa confirmada', valor: d.confirmed_cause },
        { clave: 'solution', etiqueta: 'Acción realizada', valor: d.solution },
        { clave: 'result', etiqueta: 'Resultado del cierre', valor: d.result },
    ]
})

/**
 * Nombre del cliente del ticket, resistente a que se haya dado de baja.
 *
 * `support_ticket.user_id` es `SET NULL` desde 2024: el ticket sobrevive a la
 * baja del cliente, pero hasta ahora la ficha quedaba en blanco sin explicar
 * por qué. No hay nombre congelado para el cliente —el snapshot del H-6 cubre
 * autores de notas y adjuntos—, así que se dice lo que se sabe.
 */
const nombreDelCliente = computed(() => {
    const u = ticket.value.user
    if (!u) return 'Cliente eliminado'

    const nombre = `${u.user_name || ''} ${u.user_lastname || ''}`.trim()
    return nombre || u.email || 'Cliente sin nombre'
})

const tieneDiagnostico = computed(
    () => camposDeDiagnostico.value.some((campo) => campo.valor),
)

const loading = ref(true)
const selectedFiles = ref([])
const updating = ref(false)
const fileInput = ref(null)
const toast = ref(null)

// ── Cargos del Ticket ──
const ticketCharges = ref([])
const loadingCharges = ref(false)
const showChargeForm = ref(false)
const submittingCharge = ref(false)

const unitOptions = ['Unidad', 'Metros', 'Horas', 'Kit', 'Servicio', 'Kg']
const defaultChargeItem = () => ({ description: '', quantity: 1, unit: 'Unidad', unit_price: 0 })

// "12 Metros", "1 Servicio", "3" (sin unidad).
const formatQuantity = (item) => {
    const qty = item.quantity || 0
    const unit = (item.unit || '').trim()
    return unit ? `${qty} ${unit}` : `${qty}`
}

const chargeForm = ref({
    items: [defaultChargeItem()],
    due_date: '',
    notes: '',
})

const addChargeItem = () => chargeForm.value.items.push(defaultChargeItem())
const removeChargeItem = (i) => chargeForm.value.items.splice(i, 1)

const chargeTotal = computed(() =>
    chargeForm.value.items.reduce((sum, i) => sum + (i.quantity || 0) * (i.unit_price || 0), 0)
)

const isChargeFormValid = computed(() =>
    chargeForm.value.items.every(i => i.description.trim() && i.quantity > 0 && i.unit_price >= 0)
)

const cancelCharge = () => {
    showChargeForm.value = false
    chargeForm.value = { items: [defaultChargeItem()], due_date: '', notes: '' }
}

const loadCharges = async () => {
    if (!canEdit.value) return
    try {
        loadingCharges.value = true
        const res = await api.support.getCharges(ticketId)
        ticketCharges.value = res.data
    } catch (e) {
        console.error('Error cargando cargos:', e)
    } finally {
        loadingCharges.value = false
    }
}

const submitCharge = async () => {
    if (!isChargeFormValid.value) return
    try {
        submittingCharge.value = true
        const payload = {
            items: chargeForm.value.items.map(i => ({
                description: i.description,
                quantity: i.quantity,
                unit: (i.unit || '').trim() || undefined,
                unit_price: i.unit_price,
            })),
            due_date: chargeForm.value.due_date || undefined,
            notes: chargeForm.value.notes || undefined,
        }
        const res = await api.support.generateCharge(ticketId, payload)
        toast.value?.success('Cargo generado', res.data.message || 'El cargo fue creado correctamente.')
        cancelCharge()
        loadCharges()
    } catch (e) {
        const msg = e.response?.data?.message || 'No se pudo generar el cargo.'
        toast.value?.error('Error', msg)
    } finally {
        submittingCharge.value = false
    }
}

// ── Equipos y materiales de la visita ────────────────────────────────────
//
// Lo que se puede mover lo decide el SERVIDOR en /equipment/available: lo que
// tiene encima quien opera, lo del técnico asignado al ticket, y las bodegas
// sólo si administra inventario. Aquí no se filtra nada — pintar una lista más
// larga que la que el backend acepta sólo sirve para que el usuario elija algo
// que le va a ser rechazado.
const equipmentItems = ref([])
const availableDevices = ref([])
const availableMaterials = ref([])
const installedDevices = ref([])
const equipmentSources = ref([])
// Destinos de un retiro = los custodios + la baja. Va aparte de `sources`
// porque la chatarra no es un sitio del que se pueda TOMAR nada.
const returnTargets = ref([])
const equipmentLoaded = ref(false)
const equipmentBusy = ref(false)

const devicePick = ref(null)
const returnPick = ref(null)
const returnTarget = ref(null)
const materialPick = ref(null)
const materialQty = ref(1)

// Ver la hoja va con ver el ticket; moverla exige `ticket_edit` y lo vuelve a
// comprobar el servidor. Se muestra en solo lectura a quien no puede editar
// porque saber qué equipo se le dejó al cliente es parte de leer el expediente.
// PR F3 - permiso propio, y el MISMO para ver y para mover. El backend exige
// `ticket_equipment` a secas en las cuatro rutas: si la pantalla se abriera con
// `view_support` mostraria una seccion que la API va a rechazar.
const puedeEquipos = computed(() => authStore.hasPermission('ticket_equipment'))

// Las lineas revertidas se VEN pero no suman: siguen en la hoja porque el
// expediente tiene que contar que aquel aparato se movio, y no cuentan porque
// el movimiento ya se deshizo.
const equipmentTotal = computed(() =>
    equipmentItems.value.reduce(
        (sum, it) => sum + ((it.is_return || it.is_reversed) ? 0 : (Number(it.unit_price) || 0) * (Number(it.quantity) || 0)),
        0,
    )
)

// Sólo las entregas se pueden cobrar. Un retiro es algo que el cliente
// devolvió; ofrecerlo en el cargo sería invitar a facturárselo.
// Una entrega deshecha ya no se cobra: el equipo volvio al inventario.
const cobrables = computed(() => equipmentItems.value.filter(it => !it.is_return && !it.is_reversed))

// Los equipos se agrupan por custodio para que el técnico vea de un vistazo si
// lo que está eligiendo sale de su mochila o de la bodega.
const devicesByHolder = computed(() => {
    const grupos = new Map()
    for (const d of availableDevices.value) {
        const clave = d.source_label || 'Inventario'
        if (!grupos.has(clave)) grupos.set(clave, [])
        grupos.get(clave).push(d)
    }
    return [...grupos.entries()].map(([label, items]) => ({ label, items }))
})

const deviceLabel = (d) => {
    const partes = [`${d.brand ?? ''} ${d.model ?? ''}`.trim() || 'Equipo']
    if (d.serial) partes.push(`S/N ${d.serial}`)
    else if (d.mac) partes.push(d.mac)
    return partes.join(' · ')
}

// La baja pinta el botón en rojo y avisa: no es un destino más, es sacar el
// equipo del inventario para siempre.
const vaADarDeBaja = computed(() => (returnTarget.value || '').startsWith('scrap:'))

const materialLabel = (m) => {
    const nombre = `${m.brand ?? ''} ${m.model ?? ''}`.trim() || 'Material'
    return `${nombre} — ${m.quantity}${m.unit ? ' ' + m.unit : ''} en ${m.source_label}`
}

const loadEquipment = async () => {
    if (!puedeEquipos.value) return
    try {
        const { data } = await ticketEquipmentApi.list(ticketId)
        equipmentItems.value = Array.isArray(data) ? data : []
    } catch (e) {
        // No bloquea el expediente: sin permiso, la hoja se ve sin equipos.
        console.error('Error cargando equipos del ticket:', e)
    }
}

const loadAvailableEquipment = async () => {
    if (!canEdit.value) return
    try {
        const { data } = await ticketEquipmentApi.available(ticketId)
        availableDevices.value   = data?.devices   ?? []
        availableMaterials.value = data?.materials ?? []
        installedDevices.value   = data?.installed ?? []
        equipmentSources.value   = data?.sources   ?? []
        returnTargets.value      = data?.return_targets ?? data?.sources ?? []

        // Destino por defecto del retiro: quien está operando. Es lo que pasa
        // de verdad — el técnico se lleva el equipo en la mano— y evita que
        // alguien lo mande a una bodega a la que el aparato nunca llegó.
        if (!returnTarget.value && returnTargets.value.length) {
            const yo = returnTargets.value[0]
            returnTarget.value = `${yo.type}:${yo.id ?? ''}`
        }

        equipmentLoaded.value = true
    } catch (e) {
        console.error('Error cargando inventario disponible:', e)
    }
}

/** Respuesta común a toda alta: refresca la hoja y lo que queda disponible. */
const aplicarRespuestaEquipo = async (data, titulo) => {
    equipmentItems.value = data.equipment ?? equipmentItems.value
    await loadAvailableEquipment()
    // Los avisos del ledger son gastos que no se pudieron registrar. Callarlos
    // dejaría el balance descuadrado sin que nadie se entere.
    const avisos = data.avisos ?? []
    if (avisos.length) toast.value?.info('Revisa el inventario', avisos.join(' '))
    else toast.value?.success(titulo, data.message)
}

const errorDeEquipo = (e, porDefecto) => {
    const errores = e.response?.data?.errors
    const detalle = errores ? Object.values(errores)[0]?.[0] : e.response?.data?.message
    toast.value?.error('Error', detalle || porDefecto)
}

const addDevice = async () => {
    const id = devicePick.value
    devicePick.value = null
    if (!id) return

    equipmentBusy.value = true
    try {
        const { data } = await ticketEquipmentApi.add(ticketId, { device_id: id })
        await aplicarRespuestaEquipo(data, 'Equipo entregado')
        cargarHistorial(1)
    } catch (e) {
        errorDeEquipo(e, 'No se pudo cargar el equipo.')
    } finally {
        equipmentBusy.value = false
    }
}

const retireDevice = async () => {
    if (!returnPick.value || !returnTarget.value) return

    const [tipo, id] = returnTarget.value.split(':')

    equipmentBusy.value = true
    try {
        const { data } = await ticketEquipmentApi.add(ticketId, {
            direction: 'in',
            device_id: returnPick.value,
            source_type: tipo,
            source_id: id === '' ? null : Number(id),
        })
        returnPick.value = null
        await aplicarRespuestaEquipo(data, 'Equipo retirado')
        cargarHistorial(1)
    } catch (e) {
        errorDeEquipo(e, 'No se pudo retirar el equipo.')
    } finally {
        equipmentBusy.value = false
    }
}

const addMaterial = async () => {
    const m = materialPick.value
    if (!m || !(materialQty.value > 0)) return

    equipmentBusy.value = true
    try {
        const { data } = await ticketEquipmentApi.add(ticketId, {
            stock_id: m.stock_id,
            quantity: materialQty.value,
            source_type: m.source_type,
            source_id: m.source_id,
        })
        materialPick.value = null
        materialQty.value = 1
        await aplicarRespuestaEquipo(data, 'Material cargado')
        cargarHistorial(1)
    } catch (e) {
        errorDeEquipo(e, 'No se pudo cargar el material.')
    } finally {
        equipmentBusy.value = false
    }
}

/**
 * Deshace una linea. NO la borra: el backend la marca como revertida y la deja
 * en la hoja. Por eso pide motivo, igual que reabrir una intervencion: una
 * correccion sin motivo se puede constatar, pero no auditar.
 */
const removeEquipment = async (item) => {
    const motivo = (window.prompt(
        item.is_return
            ? 'Motivo para deshacer este retiro (minimo 10 caracteres):'
            : 'Motivo para deshacer esta entrega (minimo 10 caracteres):',
    ) || '').trim()

    if (!motivo) return

    if (motivo.length < 10) {
        toast.value?.error('Falta el motivo', 'El motivo debe tener al menos 10 caracteres.')
        return
    }

    equipmentBusy.value = true
    try {
        const { data } = await ticketEquipmentApi.remove(ticketId, item.id, motivo)
        equipmentItems.value = data.equipment ?? []
        await loadAvailableEquipment()
        toast.value?.success('Listo', data.message)
        cargarHistorial(1)
    } catch (e) {
        errorDeEquipo(e, 'No se pudo deshacer la línea.')
    } finally {
        equipmentBusy.value = false
    }
}

/**
 * Trae al formulario de cargo un equipo ya entregado.
 *
 * NO cobra nada por sí mismo: copia la descripción y el precio congelado de la
 * línea para que nadie los vuelva a teclear —y los teclee mal—. La decisión de
 * facturarlo sigue siendo de quien pulsa «Generar Cargo».
 */
const chargePick = ref(null)

const addChargeFromEquipment = () => {
    const item = cobrables.value.find(x => x.id === chargePick.value)
    chargePick.value = null
    if (!item) return

    const linea = {
        description: item.label,
        quantity: item.is_device ? 1 : Number(item.quantity) || 1,
        unit: item.is_device ? 'Unidad' : (item.unit || 'Unidad'),
        unit_price: item.unit_price != null ? Number(item.unit_price) : 0,
    }

    // La primera fila nace vacía; se reemplaza en vez de dejar un ítem en
    // blanco que impediría enviar el formulario.
    const primera = chargeForm.value.items[0]
    if (chargeForm.value.items.length === 1 && !primera.description.trim() && !primera.unit_price) {
        chargeForm.value.items.splice(0, 1, linea)
    } else {
        chargeForm.value.items.push(linea)
    }
}

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

const chargeStatusClass = (status) => {
    const map = {
        paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        pending: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
        overdue: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
        issued: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    }
    return map[status] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
}

const chargeStatusLabel = (status) => {
    const map = { paid: 'Pagado', pending: 'Pendiente', overdue: 'Vencido', issued: 'Emitido', cancelled: 'Cancelado' }
    return map[status] || status
}

// Bitácora de Trabajo state
const showNoteForm = ref(false)
const noteContent = ref('')
const savingNote = ref(false)
const editingNoteId = ref(null)

// Se eliminó `currentUserId`, que leía la sesión del almacenamiento del
// navegador a mano. Dos motivos: la sesión vive en `sessionStorage` cuando no se
// marca «recordarme» —así que el valor era casi siempre el `1` por defecto— y,
// sobre todo, la identidad del autor no es cosa del cliente. Si alguna pantalla
// necesita el usuario en curso, el sitio es `useAuthStore()`, que ya consulta
// los dos almacenamientos.

const editNote = (note) => {
    editingNoteId.value = note.id
    noteContent.value = note.message
    showNoteForm.value = true
}

const cancelNote = () => {
    showNoteForm.value = false
    noteContent.value = ''
    editingNoteId.value = null
}

const saveNote = async () => {
    if (!noteContent.value.trim()) return
    
    try {
        savingNote.value = true
        if (editingNoteId.value) {
            await api.support.updateMessage(editingNoteId.value, noteContent.value)
            toast.value?.success('Nota actualizada', 'La nota se ha actualizado correctamente.')
        } else {
            // `is_internal: true` — son notas de bitácora interna.
            //
            // Ya NO se manda el autor. Antes iba `currentUserId`, leído de
            // `localStorage.userData`, que sólo existe si se marcó «recordarme»;
            // en cualquier otra sesión caía al literal 1, un usuario que no
            // existe, y el backend respondía 422. El autor lo pone el servidor a
            // partir de la sesión, que además evita firmar notas en nombre ajeno.
            await api.support.addMessage(ticketId, noteContent.value, true)
            toast.value?.success('Nota guardada', 'La nota ha sido agregada a la bitácora.')
        }

        cancelNote()
        loadTicket() // Refresh ticket to see new messages
    } catch (err) {
        console.error('Error al guardar nota:', err)

        // Se muestra el mensaje que devuelve el backend en vez de un texto
        // genérico: si la nota se rechaza por vacía o por larga, quien la
        // escribe necesita saber cuál de las dos cosas pasó.
        const validacion = err.response?.data?.errors?.message?.[0]
        const detalle = validacion
            || err.response?.data?.message
            || 'No se pudo guardar la nota en la bitácora.'

        toast.value?.error('Error', detalle)
    } finally {
        savingNote.value = false
    }
}

const deleteNote = async (id) => {
    if (!confirm('¿Estás seguro de eliminar esta nota?')) return
    
    try {
        await api.support.deleteMessage(id)
        toast.value?.success('Nota eliminada', 'La nota ha sido eliminada de la bitácora.')
        loadTicket()
    } catch (err) {
        console.error('Error al eliminar nota:', err)
        toast.value?.error('Error', 'No se pudo eliminar la nota.')
    }
}

const loadTicket = async () => {
    try {
        loading.value = true
        const response = await api.support.getOne(ticketId)
        ticket.value = response.data
    } catch (err) {
        console.error('Error al cargar ticket:', err)
        toast.value?.error('Error', 'Error al cargar los detalles del ticket.')
        router.push('/support')
    } finally {
        loading.value = false
    }
}

const lightboxImage = ref(null)
const lightboxDownload = ref(null)
const lightboxError = ref(false)

const isImage = (filename) => {
    if (!filename) return false
    const ext = filename.split('.').pop().toLowerCase()
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)
}

/**
 * Recibe el adjunto entero, no sólo una URL.
 *
 * Ver y descargar son ahora dos endpoints distintos: el primero responde
 * `inline` para que el <img> lo pinte, el segundo `attachment`. Con una sola
 * URL no se puede tener las dos cosas.
 */
const openImage = (attachment) => {
    lightboxError.value = false
    lightboxImage.value = attachment.url
    lightboxDownload.value = attachment.download_url
}

const handleFileChange = (event) => {
    const files = Array.from(event.target.files)
    
    files.forEach(file => {
        if (!file.type.startsWith('image/')) return

        const reader = new FileReader()
        reader.onload = (e) => {
            selectedFiles.value.push({
                file,
                preview: e.target.result
            })
        }
        reader.readAsDataURL(file)
    })
    
    // reset input
    event.target.value = ''
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

const updateTicket = async () => {
    try {
        updating.value = true
        
        const formData = new FormData()
        
        // `status` YA NO VIAJA AQUI. El estado se mueve por transiciones, que
        // validan de donde viene el ticket; el backend lo ignora en este PUT.
        
        // Method spoofing for files via PUT
        formData.append('_method', 'PUT') 
        
        if (selectedFiles.value.length > 0) {
            selectedFiles.value.forEach(f => {
                formData.append('attachments[]', f.file)
            })
        }
        
        await api.support.update(ticketId, formData) 
        toast.value?.success('Éxito', 'Ticket actualizado correctamente.')
        selectedFiles.value = []
        loadTicket()
    } catch (err) {
        console.error('Error al actualizar ticket:', err)
        
        if (err.response?.status === 422) {
            const validationErrors = err.response.data.errors
            const firstError = Object.values(validationErrors)[0][0]
            toast.value?.error('Error de validación', firstError)
        } else {
            toast.value?.error('Error', 'No se pudo actualizar el ticket.')
        }
    } finally {
        updating.value = false
    }
}

// Helper functions (same as Support.vue)
const getStatusBadgeClass = (status) => {
    const classes = {
        open: 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        in_progress: 'px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        resolved: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        closed: 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    }
    return classes[status] || classes.open
}

const getStatusLabel = (status) => statusLabel(status)

const getPriorityBadgeClass = (priority) => {
    const classes = {
        low: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        medium: 'px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        high: 'px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        urgent: 'px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
    }
    return classes[priority] || classes.medium
}

const getPriorityLabel = (priority) => priorityLabel(priority)

const getCategoryBadgeClass = (category) => {
    const classes = {
        technical: 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        billing: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        services: 'px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
        general: 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    }
    return classes[category] || classes.general
}

const getCategoryLabel = (category) => categoryLabel(category)

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
}

// ── Historial inalterable (PR #3 · F1-17) ──
//
// Sólo lectura. No hay acciones de editar ni de borrar aquí, y no debe haberlas:
// el requerimiento pide que la auditoría no sea editable desde la operación
// ordinaria, y el backend lanza si alguien lo intenta por código.

const historial = ref([])
const historialPagina = ref(0)
const historialHayMas = ref(false)
const historialCargando = ref(false)
const historialError = ref(false)

/**
 * Fecha en hora de Colombia, no en la del navegador.
 *
 * El servidor guarda en UTC y `formatDate` deja que cada equipo la interprete a
 * su antojo. Para una bitácora de auditoría eso es un problema: dos personas
 * mirando el mismo evento leerían horas distintas y no habría forma de
 * referirse a «las 3 de la tarde» sin ambigüedad.
 */
const formatDateBogota = (fecha) => {
    if (!fecha) return '-'
    return new Date(fecha).toLocaleString('es-CO', {
        timeZone: 'America/Bogota',
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

const NOMBRE_DE_CAMPO = {
    status: 'el estado',
    priority: 'la prioridad',
    category: 'la categoría',
    staff_id: 'el técnico asignado',
    symptom: 'el síntoma',
    suspected_cause: 'la causa sospechada',
    confirmed_cause: 'la causa confirmada',
    solution: 'la acción realizada',
    result: 'el resultado',
    no_charge: 'si la visita se le cobra al cliente',
}

/** Etiqueta guardada en el evento; si no la hay, el código; si tampoco, «sin definir». */
const valorLegible = (evento, lado) => {
    const etiqueta = evento.metadata?.[`${lado}_label`]
    if (etiqueta) return etiqueta

    const valor = lado === 'old' ? evento.old_value : evento.new_value
    return valor || 'sin definir'
}

/**
 * Frase en español para un evento.
 *
 * Se prefiere la etiqueta que quedó CONGELADA en el evento sobre el catálogo
 * actual: las etiquetas son editables por diseño, y el historial tiene que
 * seguir diciendo lo que el operador vio ese día.
 */
const etiquetaDeEvento = (evento) => {
    const meta = evento.metadata || {}

    switch (evento.event_type) {
        case 'ticket_created':
            return 'Se creó el ticket'
        case 'note_added':
            return meta.is_internal ? 'Se agregó una nota interna' : 'Se agregó una nota'
        case 'attachment_added':
            // El nombre que ve el usuario, nunca la ruta del almacenamiento.
            return `Se adjuntó el archivo «${meta.file_name || 'sin nombre'}»`
        case 'charge_created':
            return meta.invoice_number
                ? `Se generó el cargo ${meta.invoice_number}`
                : 'Se generó un cargo'
        case 'equipment_delivered':
            return `Se le entregó al cliente ${meta.label || 'un equipo'}`
        case 'equipment_returned':
            return `Se le retiró al cliente ${meta.label || 'un equipo'}`
        case 'equipment_scrapped':
            return `Se le retiró al cliente ${meta.label || 'un equipo'} y se dio de baja`
        case 'equipment_reversed': {
            const que = meta.direction === 'in' ? 'el retiro' : 'la entrega'
            const por = meta.reason ? ` · «${meta.reason}»` : ''
            return `Se deshizo ${que} de ${meta.label || 'un equipo'}${por}`
        }
        case 'no_charge_changed': {
            const sinCobro = evento.new_value === 'sin cobro al cliente'
            const motivo = meta.reason ? ` (${meta.reason})` : ''
            return sinCobro
                ? `Se marcó la visita sin cobro al cliente${motivo}`
                : 'Se quitó la marca de sin cobro: la visita vuelve a ser cobrable'
        }
        default: {
            const campo = NOMBRE_DE_CAMPO[evento.field] || evento.field || 'un campo'
            return `Cambió ${campo}: ${valorLegible(evento, 'old')} → ${valorLegible(evento, 'new')}`
        }
    }
}

/** «Sistema» cuando no hubo persona: el requerimiento pide distinguir usuario de aplicación. */
const actorDe = (evento) => {
    if (!evento.actor_user_id) return 'Sistema'

    const a = evento.actor
    if (!a) return 'Usuario dado de baja'

    const nombre = `${a.user_name || ''} ${a.user_lastname || ''}`.trim()
    return nombre || a.email || 'Usuario'
}

const cargarHistorial = async (pagina = 1) => {
    try {
        historialCargando.value = true
        historialError.value = false

        const { data } = await api.support.getHistory(ticketId, pagina)

        // Página 1 reemplaza; las siguientes acumulan hacia atrás en el tiempo.
        historial.value = pagina === 1 ? data.data : [...historial.value, ...data.data]
        historialPagina.value = data.current_page
        historialHayMas.value = Boolean(data.next_page_url)
    } catch (err) {
        console.error('Error al cargar el historial:', err)
        historialError.value = true
    } finally {
        historialCargando.value = false
    }
}

// ── PR C · Archivar y restaurar el expediente ────────────────────────────
//
// Ninguna de estas comprobaciones sustituye a la del servidor: el backend
// vuelve a validar el motivo, el número tecleado, el estado del ticket y los
// cargos vivos. Lo de aquí sólo evita que el botón se pueda pulsar sin haber
// rellenado lo que hace falta.

const modalArchivar = ref(false)
const modalRestaurar = ref(false)
const enviandoArchivo = ref(false)
const enviandoRestauracion = ref(false)

const formArchivo = ref({
    reason: '',
    reason_code: '',
    acknowledge_active: false,
    confirm_ticket_id: '',
})

const formRestauracion = ref({ reason: '' })

/** Un ticket con trabajo en curso: archivarlo exige las dos barreras extra. */
const ticketActivo = computed(
    () => ticket.value.status === 'open' || ticket.value.status === 'in_progress'
)

const archivadoListo = computed(() => {
    const f = formArchivo.value

    if (f.reason.trim().length < 10) return false
    // Comparación como texto: el campo es un input y el id llega como número.
    if (String(f.confirm_ticket_id).trim() !== String(ticket.value.id)) return false
    if (ticketActivo.value && (!f.reason_code || !f.acknowledge_active)) return false

    return true
})

const abrirArchivado = () => {
    formArchivo.value = { reason: '', reason_code: '', acknowledge_active: false, confirm_ticket_id: '' }
    modalArchivar.value = true
}

const cerrarArchivado = () => {
    modalArchivar.value = false
}

const abrirRestauracion = () => {
    formRestauracion.value = { reason: '' }
    modalRestaurar.value = true
}

const cerrarRestauracion = () => {
    modalRestaurar.value = false
}

/** Primer mensaje útil de un 422, venga como `errors` o como `message`. */
const mensajeDeError = (err, porDefecto) => {
    const errores = err.response?.data?.errors
    if (errores) return Object.values(errores)[0]?.[0] ?? porDefecto

    return err.response?.data?.message ?? porDefecto
}

const archivarTicket = async () => {
    if (!archivadoListo.value || enviandoArchivo.value) return

    try {
        enviandoArchivo.value = true

        const payload = { reason: formArchivo.value.reason.trim(), confirm_ticket_id: formArchivo.value.confirm_ticket_id }

        // Sólo se mandan cuando aplican: enviarlos siempre haría que el
        // servidor validara `reason_code` en un ticket ya cerrado, donde no
        // tiene sentido pedirlo.
        if (ticketActivo.value) {
            payload.reason_code = formArchivo.value.reason_code
            payload.acknowledge_active = formArchivo.value.acknowledge_active
        }

        const { data } = await api.support.archive(ticketId, payload)

        modalArchivar.value = false
        toast.value?.success('Ticket archivado', data.message || 'El expediente se conserva y puede restaurarse.')

        // Se recarga en vez de navegar: quien archivó puede seguir viendo el
        // expediente, y el historial acaba de ganar un evento.
        await loadTicket()
        await cargarHistorial(1)
    } catch (err) {
        console.error('Error al archivar el ticket:', err)
        toast.value?.error('No se pudo archivar', mensajeDeError(err, 'No se pudo archivar el ticket.'))
    } finally {
        enviandoArchivo.value = false
    }
}

const restaurarTicket = async () => {
    if (formRestauracion.value.reason.trim().length < 10 || enviandoRestauracion.value) return

    try {
        enviandoRestauracion.value = true

        const { data } = await api.support.restore(ticketId, {
            reason: formRestauracion.value.reason.trim(),
        })

        modalRestaurar.value = false
        toast.value?.success('Ticket restaurado', data.message || 'El expediente vuelve a la operación.')

        await loadTicket()
        await cargarHistorial(1)
    } catch (err) {
        console.error('Error al restaurar el ticket:', err)
        toast.value?.error('No se pudo restaurar', mensajeDeError(err, 'No se pudo restaurar el ticket.'))
    } finally {
        enviandoRestauracion.value = false
    }
}

// ── Ciclo de vida del ticket (Solicitud Maestra §7, §15, §18) ────────────
//
// Lo que se puede hacer LO DICE EL SERVIDOR (`GET .../transitions`). Aquí no
// hay copia de la matriz de transiciones ni de los requisitos de cierre: una
// segunda copia se desincroniza el día que alguien toca la primera, y entonces
// el panel ofrece botones que la API rechaza con 422.

const workflow = ref({
    transitions: [],
    actions: { propose_closure: false, close: false, close_exception: false, reopen: false },
    closureRequirements: { missing: [], complete: true },
    // `isTerminal` y `reopenBlockedBy` los decide el servidor: la pantalla no
    // conoce ni la matriz ni los permisos efectivos, y adivinarlos fue
    // justamente lo que dejó al operador sin saber por qué no podía reabrir.
    isTerminal: false,
    statusLabel: '',
    reopenBlockedBy: null,
    reopenPermission: 'ticket_reopen',
})

const algunaAccion = computed(() => Object.values(workflow.value.actions).some(Boolean))

const cargarWorkflow = async () => {
    try {
        const { data } = await api.support.getTransitions(ticketId)

        workflow.value = {
            transitions: data.transitions ?? [],
            actions: data.actions ?? {},
            closureRequirements: {
                missing: data.closure_requirements?.missing ?? [],
                complete: data.closure_requirements?.complete ?? true,
            },
            isTerminal: data.is_terminal ?? false,
            statusLabel: data.status_label ?? '',
            reopenBlockedBy: data.reopen_blocked_by ?? null,
            reopenPermission: data.reopen_permission ?? 'ticket_reopen',
        }
    } catch (err) {
        console.error('Error al cargar las acciones del ticket:', err)
        // Sin datos, no se ofrece nada. Es la experiencia segura: pintar
        // botones a ciegas terminaría en 422 delante del operador.
        workflow.value = {
            transitions: [], actions: {},
            closureRequirements: { missing: [], complete: true },
            isTerminal: false, statusLabel: '', reopenBlockedBy: null, reopenPermission: 'ticket_reopen',
        }
    }
}

/**
 * Lo único que cambia entre las cinco acciones.
 *
 * `motivoObligatorio` refleja lo que exige el backend, no una preferencia de la
 * pantalla: la propuesta, el cierre especial y la reapertura lo piden; la
 * transición ordinaria y el cierre normal lo aceptan opcional.
 */
const ACCIONES = {
    transition: {
        clave: 'transition',
        titulo: 'Cambiar el estado',
        descripcion: 'El ticket avanza en el flujo. Queda registrado quién lo movió y desde dónde.',
        etiquetaMotivo: 'Motivo (opcional)',
        placeholder: 'Por ejemplo: falta una ONU del modelo que pide la instalación…',
        motivoObligatorio: false,
        textoBoton: 'Mover',
        cabecera: 'bg-blue-50/60 dark:bg-gray-700/30',
        boton: 'bg-blue-600 hover:bg-blue-700',
    },
    propose: {
        clave: 'propose',
        titulo: 'Proponer el cierre',
        descripcion: 'NO cierra el ticket: lo deja en observación, a la espera de que el supervisor lo revise.',
        etiquetaMotivo: 'Observación técnica',
        placeholder: 'Qué se hizo y cómo quedó el servicio…',
        motivoObligatorio: true,
        textoBoton: 'Proponer cierre',
        cabecera: 'bg-indigo-50/60 dark:bg-gray-700/30',
        boton: 'bg-indigo-600 hover:bg-indigo-700',
    },
    close: {
        clave: 'close',
        titulo: 'Cerrar el ticket',
        descripcion: 'Cerrar significa que la causa, la acción y el resultado quedaron documentados. No borra nada del expediente.',
        etiquetaMotivo: 'Observación de cierre (opcional)',
        placeholder: 'Por ejemplo: el cliente confirma el servicio…',
        motivoObligatorio: false,
        textoBoton: 'Cerrar ticket',
        cabecera: 'bg-emerald-50/60 dark:bg-gray-700/30',
        boton: 'bg-emerald-600 hover:bg-emerald-700',
    },
    exception: {
        clave: 'exception',
        titulo: 'Cierre especial',
        descripcion: 'Autoriza cerrar sin cumplir todos los requisitos. Queda constancia de cuál faltó y de quién lo autorizó.',
        etiquetaMotivo: 'Justificación de la excepción',
        placeholder: 'Por qué se autoriza cerrar sin ese requisito…',
        motivoObligatorio: true,
        textoBoton: 'Autorizar y cerrar',
        cabecera: 'bg-amber-50/60 dark:bg-gray-700/30',
        boton: 'bg-amber-600 hover:bg-amber-700',
    },
    reopen: {
        clave: 'reopen',
        titulo: 'Reabrir el ticket',
        descripcion: 'Vuelve al flujo de trabajo. La fecha del cierre anterior se conserva.',
        etiquetaMotivo: 'Motivo de la reapertura',
        placeholder: 'Por ejemplo: la falla reapareció en el mismo servicio…',
        motivoObligatorio: true,
        textoBoton: 'Reabrir',
        cabecera: 'bg-blue-50/60 dark:bg-gray-700/30',
        boton: 'bg-blue-600 hover:bg-blue-700',
    },
}

const accion = ref(null)
const accionDestino = ref(null)
const motivoAccion = ref('')
const ejecutandoAccion = ref(false)

const accionLista = computed(() => {
    if (!accion.value) return false

    return !accion.value.motivoObligatorio || motivoAccion.value.trim().length >= 10
})

const abrirAccion = (clave, destino = null) => {
    const base = ACCIONES[clave]
    if (!base) return

    accion.value = clave === 'transition' && destino
        ? { ...base, titulo: `Mover a «${destino.label}»` }
        : { ...base }

    accionDestino.value = destino
    motivoAccion.value = ''
}

const cerrarAccion = () => {
    accion.value = null
    accionDestino.value = null
    exencionPruebaFinal.value = { reason: null, note: '' }
}

/**
 * Solo se pide la justificacion cuando de verdad falta la medicion final, y solo
 * en propuesta y cierre ordinario. El cierre EXCEPCIONAL no la pide: ya deja
 * constancia del requisito incumplido por su propia via, y pedirla ahi seria
 * exigir dos veces lo mismo.
 */
const pideJustificacionDePruebaFinal = computed(() =>
    ['propose', 'close'].includes(accion.value?.clave)
    && workflow.value.closureRequirements.missing.some((m) => m.includes('Medición final')),
)

/** La exencion solo viaja si el usuario la lleno; si no, el backend la exige. */
const exencion = () => {
    if (!pideJustificacionDePruebaFinal.value || !exencionPruebaFinal.value.reason) return {}

    return {
        final_test_waiver_reason: exencionPruebaFinal.value.reason,
        final_test_waiver_note: exencionPruebaFinal.value.note,
    }
}

const ejecutarAccion = async () => {
    if (!accion.value || !accionLista.value || ejecutandoAccion.value) return

    const motivo = motivoAccion.value.trim() || null

    try {
        ejecutandoAccion.value = true

        const { data } = await (() => {
            switch (accion.value.clave) {
                case 'transition': return api.support.updateStatus(ticketId, accionDestino.value.code, motivo)
                case 'propose':    return api.support.proposeClosure(ticketId, motivo, exencion())
                case 'close':      return api.support.closeTicket(ticketId, motivo, exencion())
                case 'exception':  return api.support.closeException(ticketId, motivo)
                case 'reopen':     return api.support.reopen(ticketId, motivo)
            }
        })()

        cerrarAccion()
        toast.value?.success('Listo', data.message || 'El ticket se actualizó.')

        await loadTicket()
        await cargarWorkflow()
        await cargarHistorial(1)
    } catch (err) {
        console.error('Error en la acción del ciclo de vida:', err)

        // El backend explica QUÉ falta y hacia dónde se puede ir; se muestra
        // entero. Ver el contenedor global de avisos.
        const errores = err.response?.data?.errors
        const detalle = errores ? Object.values(errores)[0]?.[0] : err.response?.data?.message

        toast.value?.error('No se pudo completar', detalle || 'No se pudo completar la acción.')
    } finally {
        ejecutandoAccion.value = false
    }
}

onMounted(() => {
    cargarCatalogos()
    loadTicket()
    loadCharges()
    cargarHistorial(1)
    cargarWorkflow()
    // PR F1: solo hace falta para poblar los desplegables de la intervencion.
    if (canIntervene.value) loadStaff()
    loadEquipment()
    loadAvailableEquipment()
})
</script>

<style scoped>
/* Quita las flechas del input numérico (se encimaban con el valor). */
.charge-num::-webkit-outer-spin-button,
.charge-num::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.charge-num {
    -moz-appearance: textfield;
    appearance: textfield;
}
</style>
