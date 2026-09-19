<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Soporte</h1>
                <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Gestión de tickets de soporte</p>
            </div>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                <!-- PR C · La vista de archivados sólo existe para quien puede
                     archivar o restaurar. No se oculta un botón a quien podría
                     usarlo: se oculta a quien la API le respondería 403. -->
                <button
                    v-if="canSeeArchived"
                    @click="alternarVista"
                    class="flex w-full items-center justify-center gap-2 rounded-lg border px-4 py-2.5 transition sm:w-auto sm:px-6 sm:py-3"
                    :class="vistaArchivados
                        ? 'border-amber-600 bg-amber-600 text-white hover:bg-amber-700'
                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                >
                    <v-icon name="bi-archive" class="h-4 w-4" />
                    {{ vistaArchivados ? 'Ver tickets activos' : 'Ver archivados' }}
                </button>
                <button
                    v-if="canCreate && !vistaArchivados"
                    @click="router.push('/support/create')"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition w-full sm:w-auto"
                >
                    <icon-lucide-plus class="w-4 h-4" />
                    Nuevo Ticket
                </button>
            </div>
        </div>

        <!-- Buscador y Filtros -->
        <div class="mb-6 flex flex-col gap-3 sm:gap-4 items-stretch">
            <!-- Buscador -->
            <div class="relative flex-1">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por asunto o descripción..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-2.5 sm:py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm sm:text-base"
                />
                <v-icon name="io-search" class="absolute left-3 top-2.5 sm:top-3.5 w-5 h-5 text-gray-400" />
                <button
                    v-if="searchQuery"
                    @click="searchQuery = ''"
                    class="absolute right-3 top-2 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                    <v-icon name="io-close-circle" class="w-6 h-6" />
                </button>
            </div>

            <!-- Filtros -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2 sm:gap-3">
                <select
                    v-model="filters.status"
                    class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                >
                    <!-- R2: las opciones salen del catálogo, no de una lista en duro -->
                    <option value="all">Todos los estados</option>
                    <option v-for="s in statuses" :key="s.code" :value="s.code">{{ s.label }}</option>
                </select>

                <select
                    v-model="filters.priority"
                    class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                >
                    <option value="all">Todas las prioridades</option>
                    <option v-for="p in priorities" :key="p.code" :value="p.code">{{ p.label }}</option>
                </select>

                <select
                    v-model="filters.category"
                    class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                >
                    <option value="all">Todas las categorías</option>
                    <option v-for="c in categories" :key="c.code" :value="c.code">{{ c.label }}</option>
                </select>

                <select
                    v-model="filters.staff"
                    class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                >
                    <option value="all">Todos los técnicos</option>
                    <option value="unassigned">Sin asignar</option>
                    <option
                        v-for="staff in staffList"
                        :key="staff.id"
                        :value="staff.id"
                    >
                        {{ staff.user_name }} {{ staff.user_lastname }}
                    </option>
                </select>
            </div>
        </div>

        <template v-if="!vistaArchivados">
        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando tickets...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
        </div>

        <!-- Tabla / Cards -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">

            <!-- Desktop table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">ID</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Asunto</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Cliente</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Categoría</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Estado</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Prioridad</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Técnico</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Fecha</th>
                            <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-for="ticket in filteredTickets" :key="ticket.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">#{{ ticket.id }}</td>
                            <td class="px-6 py-4 text-sm text-gray-800 dark:text-white font-medium">{{ ticket.subject }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ ticket.user?.user_name }} {{ ticket.user?.user_lastname }}
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span :class="getCategoryBadgeClass(ticket.category)">{{ getCategoryLabel(ticket.category) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span :class="getStatusBadgeClass(ticket.status)">{{ getStatusLabel(ticket.status) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span :class="getPriorityBadgeClass(ticket.priority)">{{ getPriorityLabel(ticket.priority) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ ticket.staff ? `${ticket.staff.user_name} ${ticket.staff.user_lastname}` : 'Sin asignar' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ formatDate(ticket.created_at) }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2 flex-wrap">
                                    <button
                                        @click="router.push(`/support/${ticket.id}`)"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                            bg-blue-50 text-blue-700 border border-blue-200
                                            hover:bg-blue-100 hover:scale-[1.03] transition-all
                                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                                    >
                                        <icon-lucide-eye class="w-4 h-4" /> Ver
                                    </button>
                                    <button
                                        v-if="canEdit"
                                        @click="router.push(`/support/${ticket.id}/edit`)"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                            bg-green-50 text-green-700 border border-green-200
                                            hover:bg-green-100 hover:scale-[1.03] transition-all
                                            dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                                    >
                                        <icon-lucide-pencil class="w-4 h-4" /> Editar
                                    </button>
                                    <!-- El botón «Eliminar» se retiró: el ticket es un
                                         expediente y su historial no puede destruirse. El
                                         archivado reversible llega en una fase posterior.
                                         Ver docs/cliente/CNO/DISENO_PERMISOS_Y_ARCHIVADO.md -->
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile cards -->
            <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="ticket in filteredTickets" :key="ticket.id" class="p-4">
                    <div class="space-y-3">
                        <div class="flex justify-between items-start gap-2">
                            <div class="min-w-0">
                                <span class="text-xs text-gray-400 dark:text-gray-500">#{{ ticket.id }}</span>
                                <h3 class="font-semibold text-gray-800 dark:text-white text-sm leading-snug">{{ ticket.subject }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ ticket.user?.user_name }} {{ ticket.user?.user_lastname }}
                                </p>
                            </div>
                            <span :class="getStatusBadgeClass(ticket.status)" class="shrink-0 text-xs">
                                {{ getStatusLabel(ticket.status) }}
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-1.5 text-xs">
                            <div><span class="text-gray-400">Categoría:</span> <span class="ml-1">{{ getCategoryLabel(ticket.category) }}</span></div>
                            <div><span class="text-gray-400">Prioridad:</span> <span class="ml-1">{{ getPriorityLabel(ticket.priority) }}</span></div>
                            <div class="col-span-2"><span class="text-gray-400">Técnico:</span> <span class="ml-1">{{ ticket.staff ? `${ticket.staff.user_name} ${ticket.staff.user_lastname}` : 'Sin asignar' }}</span></div>
                            <div class="col-span-2"><span class="text-gray-400">Fecha:</span> <span class="ml-1">{{ formatDate(ticket.created_at) }}</span></div>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <button
                                @click="router.push(`/support/${ticket.id}`)"
                                class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                    bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-all
                                    dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
                            >
                                <icon-lucide-eye class="w-3.5 h-3.5" /> Ver
                            </button>
                            <button
                                v-if="canEdit"
                                @click="router.push(`/support/${ticket.id}/edit`)"
                                class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                    bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 transition-all
                                    dark:bg-green-900/30 dark:text-green-300 dark:border-green-800"
                            >
                                <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="filteredTickets.length === 0 && !loading" class="p-8 text-center text-gray-500 dark:text-gray-400">
                    {{ searchQuery ? 'No se encontraron resultados' : 'No hay tickets registrados' }}
                </div>
            </div>
        </div>
        </template>

        <!-- ── PR C · Expedientes archivados ────────────────────────────────
             Sólo para quien puede archivar o restaurar. Paginado en el servidor:
             los archivados sólo crecen y una consulta sin límite envejece mal. -->
        <template v-if="vistaArchivados">
            <div v-if="cargandoArchivados" class="py-12 text-center">
                <div class="inline-block h-12 w-12 animate-spin rounded-full border-4 border-amber-500 border-t-transparent"></div>
                <p class="mt-4 text-gray-500 dark:text-gray-400">Cargando expedientes archivados...</p>
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-md dark:border-gray-700 dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-amber-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">ID</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Asunto</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Cliente</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Estado al archivar</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Motivo</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Archivado por</th>
                                <th class="px-4 py-4 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Fecha</th>
                                <th class="px-4 py-4 text-center text-xs font-medium uppercase text-gray-600 dark:text-gray-300">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr
                                v-for="t in archivados"
                                :key="t.id"
                                class="transition hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <td class="px-4 py-4 text-sm text-gray-800 dark:text-white">#{{ t.id }}</td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-800 dark:text-white">{{ t.subject }}</td>
                                <td class="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                                    {{ t.user?.user_name }} {{ t.user?.user_lastname }}
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <span :class="getStatusBadgeClass(t.status)">{{ getStatusLabel(t.status) }}</span>
                                </td>
                                <td class="max-w-xs px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                                    {{ t.archived_reason }}
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                                    {{ t.archiver ? `${t.archiver.user_name} ${t.archiver.user_lastname}` : '—' }}
                                </td>
                                <td class="px-4 py-4 text-sm text-gray-600 dark:text-gray-300">
                                    {{ formatDate(t.archived_at) }}
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="flex flex-wrap justify-center gap-2">
                                        <button
                                            @click="router.push(`/support/${t.id}`)"
                                            class="flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition-all hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300"
                                        >
                                            <icon-lucide-eye class="h-4 w-4" /> Ver
                                        </button>
                                        <button
                                            v-if="canRestore"
                                            @click="abrirRestauracion(t)"
                                            class="flex items-center gap-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 transition-all hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300"
                                        >
                                            <v-icon name="ri-arrow-go-back-line" class="h-4 w-4" /> Restaurar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="archivados.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
                    No hay expedientes archivados.
                </div>

                <div
                    v-if="archivadosPaginas > 1"
                    class="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-700"
                >
                    <button
                        :disabled="archivadosPagina <= 1"
                        @click="cargarArchivados(archivadosPagina - 1)"
                        class="rounded-lg px-3 py-1.5 text-sm text-gray-600 transition hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Anterior
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Página {{ archivadosPagina }} de {{ archivadosPaginas }} · {{ archivadosTotal }} expedientes
                    </span>
                    <button
                        :disabled="archivadosPagina >= archivadosPaginas"
                        @click="cargarArchivados(archivadosPagina + 1)"
                        class="rounded-lg px-3 py-1.5 text-sm text-gray-600 transition hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Siguiente
                    </button>
                </div>
            </div>
        </template>

        <!-- Restaurar desde el listado. Motivo obligatorio, igual que archivar. -->
        <Teleport to="body">
            <div
                v-if="ticketARestaurar"
                class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
                @click="cerrarRestauracion"
            >
                <div
                    class="w-full max-w-lg overflow-hidden rounded-xl border border-gray-100 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800"
                    @click.stop
                >
                    <div class="border-b border-gray-100 bg-blue-50/60 p-6 dark:border-gray-700 dark:bg-gray-700/30">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            Restaurar el ticket #{{ ticketARestaurar.id }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ ticketARestaurar.subject }}
                        </p>
                    </div>

                    <div class="p-6">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Motivo de la restauración <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="motivoRestauracion"
                            rows="3"
                            maxlength="500"
                            placeholder="Explica por qué vuelve a la operación…"
                            class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ motivoRestauracion.length }}/500 · mínimo 10 caracteres. Queda en el historial.
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
                            :disabled="motivoRestauracion.trim().length < 10 || restaurando"
                            @click="restaurarTicket"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ restaurando ? 'Restaurando…' : 'Restaurar expediente' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>


<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { usePermissions } from '@/composables/usePermissions'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import NotificationToast from '../components/NotificationToast.vue'

const { can } = usePermissions()

const router = useRouter()

const tickets = ref([])
const staffList = ref([])
const loading = ref(true)
const error = ref('')
const toast = ref(null)
const searchQuery = ref('')
const filters = ref({
    status: 'all',
    priority: 'all',
    category: 'all',
    staff: 'all'
})

// PR B · Capacidades separadas. No hay respaldo a `view_support`: si un rol
// tuviera el permiso antiguo y le faltara el granular —datos inconsistentes,
// backfill no ejecutado— la acción se OCULTA. Es la experiencia segura; elevar
// el privilegio en silencio dejaría el panel ofreciendo lo que la API rechaza.
const canCreate = computed(() => can('ticket_create'))
const canEdit = computed(() => can('ticket_edit'))
// `canDelete` se eliminó junto con el botón: no existe borrado de tickets.
// Lo que sí existe desde el PR C es ARCHIVAR, más abajo.

const filteredTickets = computed(() => {
    let result = tickets.value

    // Filtro de búsqueda
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase().trim()
        result = result.filter(ticket => {
            return (
                ticket.subject?.toLowerCase().includes(query) ||
                ticket.description?.toLowerCase().includes(query) ||
                ticket.user?.user_name?.toLowerCase().includes(query) ||
                ticket.user?.user_lastname?.toLowerCase().includes(query)
            )
        })
    }

    // Filtros
    if (filters.value.status !== 'all') {
        result = result.filter(t => t.status === filters.value.status)
    }
    if (filters.value.priority !== 'all') {
        result = result.filter(t => t.priority === filters.value.priority)
    }
    if (filters.value.category !== 'all') {
        result = result.filter(t => t.category === filters.value.category)
    }
    if (filters.value.staff !== 'all') {
        if (filters.value.staff === 'unassigned') {
            result = result.filter(t => !t.staff_id)
        } else {
            result = result.filter(t => t.staff_id === parseInt(filters.value.staff))
        }
    }

    return result
})

const loadStaff = async () => {
    try {
        const response = await api.staff.getAll()
        const allUsers = response.data.data || []
        // Filtrar solo técnicos por NOMBRE de rol: los roles son por tenant y el id
        // varía, por eso no se usa role_id fijo. Tolera acentos/mayúsculas.
        staffList.value = allUsers.filter(user => {
            const n = (user.role_name || '').trim().toLowerCase()
            return n === 'técnico' || n === 'tecnico'
        })
    } catch (err) {
        console.error('Error loading staff:', err)
    }
}

const loadTickets = async () => {
    try {
        loading.value = true
        const response = await api.support.getAll()
        tickets.value = response.data
    } catch (err) {
        console.error('Error al cargar tickets:', err)
        toast.value?.error('Error', 'No se pudieron cargar los tickets de soporte.')
        error.value = 'Error al cargar los tickets.'
    } finally {
        loading.value = false
    }
}

// R2: las ETIQUETAS vienen del catálogo; los COLORES se quedan aquí porque se
// deciden por código —que es estable— y son presentación, no dato de negocio.
const {
    statuses, priorities, categories,
    cargar: cargarCatalogos,
    statusLabel, priorityLabel, categoryLabel,
} = useTicketCatalogs()

// Helper functions (Badges and Labels)
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

// ── PR C · Expedientes archivados ────────────────────────────────────────
//
// El listado ordinario filtra en el cliente porque trae todos los tickets de
// una vez. Éste NO: los archivados sólo crecen —nada los saca de ahí salvo
// restaurarlos— así que va paginado en el servidor y los filtros viajan como
// parámetros. Es la misma pantalla, con otra fuente de datos.

const vistaArchivados = ref(false)
const archivados = ref([])
const cargandoArchivados = ref(false)
const archivadosPagina = ref(1)
const archivadosPaginas = ref(1)
const archivadosTotal = ref(0)

// Ver archivados va con cualquiera de los dos permisos: hoy los dos van al
// mismo rol y el cliente pidió simplicidad. Ver DISENO §0, supuesto S-3.
const canSeeArchived = computed(() => can('ticket_archive') || can('ticket_restore'))
const canRestore = computed(() => can('ticket_restore'))

const alternarVista = () => {
    vistaArchivados.value = !vistaArchivados.value

    if (vistaArchivados.value) {
        cargarArchivados(1)
    }
}

const cargarArchivados = async (pagina = 1) => {
    try {
        cargandoArchivados.value = true

        const params = { page: pagina }

        if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
        if (filters.value.status !== 'all') params.status = filters.value.status
        if (filters.value.priority !== 'all') params.priority = filters.value.priority
        if (filters.value.category !== 'all') params.category = filters.value.category

        const { data } = await api.support.getArchived(params)

        archivados.value = data.data ?? []
        archivadosPagina.value = data.current_page ?? 1
        archivadosPaginas.value = data.last_page ?? 1
        archivadosTotal.value = data.total ?? 0
    } catch (err) {
        console.error('Error al cargar los expedientes archivados:', err)
        toast.value?.error('Error', 'No se pudieron cargar los expedientes archivados.')
    } finally {
        cargandoArchivados.value = false
    }
}

// Los filtros de arriba son los mismos para las dos vistas; en la de
// archivados hay que volver a preguntar al servidor porque no están en memoria.
watch([searchQuery, filters], () => {
    if (vistaArchivados.value) {
        cargarArchivados(1)
    }
}, { deep: true })

// ── Restaurar desde el listado ──

const ticketARestaurar = ref(null)
const motivoRestauracion = ref('')
const restaurando = ref(false)

const abrirRestauracion = (ticket) => {
    ticketARestaurar.value = ticket
    motivoRestauracion.value = ''
}

const cerrarRestauracion = () => {
    ticketARestaurar.value = null
}

const restaurarTicket = async () => {
    if (!ticketARestaurar.value || motivoRestauracion.value.trim().length < 10 || restaurando.value) return

    try {
        restaurando.value = true

        const { data } = await api.support.restore(ticketARestaurar.value.id, {
            reason: motivoRestauracion.value.trim(),
        })

        toast.value?.success('Ticket restaurado', data.message || 'El expediente vuelve a la operación.')
        ticketARestaurar.value = null

        // Las dos listas cambian: uno sale de archivados y entra en activos.
        await cargarArchivados(archivadosPagina.value)
        await loadTickets()
    } catch (err) {
        console.error('Error al restaurar el ticket:', err)
        const errores = err.response?.data?.errors
        const detalle = errores
            ? Object.values(errores)[0]?.[0]
            : err.response?.data?.message

        toast.value?.error('No se pudo restaurar', detalle || 'No se pudo restaurar el ticket.')
    } finally {
        restaurando.value = false
    }
}

onMounted(() => {
    cargarCatalogos()
    loadStaff()
    loadTickets()
})
</script>
