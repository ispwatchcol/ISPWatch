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
                </div>
                <div class="flex gap-2">
                    <button
                        v-if="canEdit"
                        @click="router.push(`/support/${ticketId}/edit`)"
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition"
                    >
                    <v-icon name="fa-edit" class="w-5 h-5 inline mr-2"></v-icon>
                        Editar
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

                    <!-- Historial inalterable (PR #3 · F1-17) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
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
                                v-if="canEdit"
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
                                            {{ message.user?.user_name?.charAt(0) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ message.user?.user_name }}</p>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ formatDate(message.created_at) }}</p>
                                        </div>
                                    </div>
                                    <div v-if="canEdit" class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
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
                                    <span class="text-sm text-gray-800 dark:text-white">{{ attachment.file_name }}</span>
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
                          class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
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
                   
                   <!-- Cargos del Ticket (Staff Only) -->
                    <div v-if="canEdit" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Cargos del Ticket</h2>
                            <button
                                @click="showChargeForm = !showChargeForm"
                                class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded-lg flex items-center gap-1 transition"
                            >
                                <v-icon name="md-add" class="w-4 h-4" />
                                Nuevo Cargo
                            </button>
                        </div>

                        <!-- Formulario nuevo cargo -->
                        <div v-if="showChargeForm" class="mb-6 bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg border border-gray-200 dark:border-gray-600 space-y-4">
                            <div class="flex items-center gap-2 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm">
                                <v-icon name="bi-person" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                <span class="text-gray-600 dark:text-gray-300">Se cobrará a:</span>
                                <strong class="text-gray-800 dark:text-white">{{ ticket.user?.user_name }} {{ ticket.user?.user_lastname }}</strong>
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

                            <button @click="addChargeItem" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 flex items-center gap-1">
                                <v-icon name="md-add" class="w-4 h-4" />
                                Agregar ítem
                            </button>

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

                   <!-- Gestión del Ticket (Staff Only) -->
                    <div v-if="canEdit" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
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
                                <span class="font-semibold">Nombre:</span> {{ ticket.user?.user_name }} {{ ticket.user?.user_lastname }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Email:</span> {{ ticket.user?.email }}
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-semibold">Teléfono:</span> {{ ticket.user?.tel }}
                            </p>
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
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import NotificationToast from '../components/NotificationToast.vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'

// R2: las ETIQUETAS vienen del catálogo; los COLORES se quedan abajo porque
// se deciden por código —que es estable— y son presentación.
const {
    statuses,
    cargar: cargarCatalogos,
    statusLabel, priorityLabel, categoryLabel,
} = useTicketCatalogs()

const router = useRouter()
const route = useRoute()
const ticketId = route.params.id

const authStore = useAuthStore()
const canEdit = computed(() => authStore.hasPermission('view_support'))

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
        
        // Only append if it exists and is valid
        if (ticket.value.status) {
            formData.append('status', ticket.value.status)
        }
        
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

onMounted(() => {
    cargarCatalogos()
    loadTicket()
    loadCharges()
    cargarHistorial(1)
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
