<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />

        <TicketSubjectsManager
            :abierto="gestorAsuntosAbierto"
            @close="gestorAsuntosAbierto = false"
            @changed="alCambiarAsuntos"
            @error="(m) => toast?.error('Asuntos', m)"
        />

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5 gap-3">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Soporte</h1>
                <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">
                    {{ vistaArchivados ? 'Expedientes archivados' : 'Tickets por atender' }}
                </p>
            </div>
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <!-- PR C · La vista de archivados sólo existe para quien puede
                     archivar o restaurar. -->
                <button
                    v-if="canSeeArchived"
                    @click="alternarVista"
                    class="flex flex-1 items-center justify-center gap-2 rounded-lg border px-3 py-2.5 text-sm transition sm:flex-none sm:px-4"
                    :class="vistaArchivados
                        ? 'border-amber-600 bg-amber-600 text-white hover:bg-amber-700'
                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                >
                    <v-icon name="bi-archive" class="h-4 w-4" />
                    {{ vistaArchivados ? 'Ver tickets activos' : 'Ver archivados' }}
                </button>
                <!-- Catálogo de asuntos del ISP: sólo quien puede administrarlo. -->
                <button
                    v-if="canManageSubjects"
                    @click="gestorAsuntosAbierto = true"
                    class="flex flex-1 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:flex-none sm:px-4"
                >
                    <v-icon name="hi-ticket" class="h-4 w-4" />
                    Asuntos
                </button>
                <button
                    v-if="canCreate && !vistaArchivados"
                    @click="router.push('/support/create')"
                    class="order-first w-full bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 rounded-lg flex items-center justify-center gap-2 transition sm:order-none sm:w-auto font-semibold"
                >
                    <icon-lucide-plus class="w-4 h-4" />
                    Nuevo Ticket
                </button>
            </div>
        </div>

        <!-- Pestañas de la cola. Los números vienen del servidor y respetan
             los filtros de abajo (búsqueda, asunto, prioridad, técnico). -->
        <div v-if="!vistaArchivados" class="-mx-3 mb-4 overflow-x-auto px-3 sm:mx-0 sm:px-0" data-testid="ticket-tabs">
            <div class="flex min-w-max gap-1 rounded-xl bg-white p-1 shadow-sm dark:bg-gray-800">
                <button
                    v-for="t in PESTANAS"
                    :key="t.key"
                    @click="cambiarPestana(t.key)"
                    :data-testid="`tab-${t.key}`"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition"
                    :class="pestana === t.key
                        ? 'bg-blue-600 text-white shadow'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                >
                    <span v-if="t.grupo" :class="groupDotClass(t.grupo)"></span>
                    <v-icon v-else :name="t.icono" class="h-4 w-4" />
                    {{ t.label }}
                    <span
                        class="rounded-full px-1.5 text-xs font-semibold"
                        :class="pestana === t.key ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                    >{{ contadores[t.key] ?? 0 }}</span>
                </button>
            </div>
        </div>

        <!-- Buscador y filtros -->
        <div class="mb-4 grid grid-cols-2 gap-2 lg:grid-cols-6">
            <div class="relative col-span-2">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar: #número, cliente, teléfono, dirección…"
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-2.5 pl-10 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                />
                <v-icon name="io-search" class="absolute left-3 top-3 w-4 h-4 text-gray-400" />
                <button
                    v-if="searchQuery"
                    @click="searchQuery = ''"
                    class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    title="Limpiar"
                >
                    <v-icon name="io-close-circle" class="w-5 h-5" />
                </button>
            </div>

            <!-- Asunto del catálogo. «Otro» agrupa los escritos a mano. -->
            <select v-model="filters.subject" class="filtro">
                <option value="all">Asunto: todos</option>
                <option v-for="s in asuntosParaFiltrar" :key="s.id" :value="String(s.id)">
                    {{ s.name }}{{ s.is_active === false ? ' (desactivado)' : '' }}
                </option>
                <option value="other">Otro (escrito a mano)</option>
            </select>

            <select v-model="filters.priority" class="filtro">
                <option value="all">Prioridad: todas</option>
                <option v-for="p in priorities" :key="p.code" :value="p.code">{{ p.label }}</option>
            </select>

            <select v-if="!vistaArchivados" v-model="filters.staff" class="filtro">
                <option value="all">Técnico: todos</option>
                <option value="unassigned">Sin asignar</option>
                <option v-for="staff in staffList" :key="staff.id" :value="String(staff.id)">
                    {{ staff.user_name }} {{ staff.user_lastname }}
                </option>
            </select>

            <select v-if="!vistaArchivados" v-model="orden" class="filtro" title="Orden">
                <option value="urgency">Urgentes primero</option>
                <option value="recent">Recientes primero</option>
            </select>
        </div>

        <template v-if="!vistaArchivados">
            <!-- Error -->
            <div v-if="error" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
                {{ error }}
                <button @click="cargarTablero" class="ml-2 underline">Reintentar</button>
            </div>

            <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
                <!-- Cargando: velo sobre la tabla, sin vaciarla (cambiar de
                     pestaña no debe hacer parpadear la página entera). -->
                <div v-if="loading" class="absolute inset-0 z-10 flex items-start justify-center bg-white/60 pt-16 dark:bg-gray-800/60">
                    <div class="h-10 w-10 animate-spin rounded-full border-4 border-blue-500 border-t-transparent"></div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="th">#</th>
                                <th class="th">Asunto</th>
                                <th class="th">Cliente</th>
                                <th class="th">Estado</th>
                                <th class="th">Prioridad</th>
                                <th class="th">Técnico</th>
                                <th class="th">{{ pestana === 'closed' ? 'Cerrado' : 'Antigüedad' }}</th>
                                <th class="th w-12"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr
                                v-for="ticket in tickets"
                                :key="ticket.id"
                                @click="abrir(ticket)"
                                class="cursor-pointer hover:bg-blue-50/60 dark:hover:bg-gray-700 transition"
                                :data-testid="`ticket-row-${ticket.id}`"
                            >
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">#{{ ticket.id }}</td>
                                <td class="px-4 py-3 text-sm text-gray-800 dark:text-white">
                                    <span class="font-medium">{{ ticket.subject }}</span>
                                    <span v-if="ticket.no_charge"
                                        :title="ticket.no_charge_reason || 'Esta visita no se le cobra al cliente'"
                                        class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        Sin cobro
                                    </span>
                                    <p v-if="ticket.customer_address" class="mt-0.5 max-w-xs truncate text-xs text-gray-500 dark:text-gray-400">
                                        <v-icon name="md-locationon" class="inline h-3 w-3" /> {{ ticket.customer_address }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                    {{ nombreCliente(ticket) }}
                                    <p v-if="ticket.user?.tel" class="text-xs text-gray-500 dark:text-gray-400">{{ ticket.user.tel }}</p>
                                    <span v-if="ticket.service_status === 'suspendido'" class="mt-0.5 inline-block rounded bg-red-100 px-1.5 text-[10px] font-semibold uppercase text-red-700 dark:bg-red-900/30 dark:text-red-300">Cortado</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span :class="groupBadgeClass(ticket.status_group)">{{ ticket.status_label }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span :class="priorityBadgeClass(ticket.priority)">{{ ticket.priority_label || priorityLabel(ticket.priority) }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                    <span v-if="ticket.staff">{{ ticket.staff.user_name }} {{ ticket.staff.user_lastname }}</span>
                                    <span v-else class="italic text-gray-400">Sin asignar</span>
                                </td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap" :title="formatDate(ticket.created_at)">
                                    <template v-if="pestana === 'closed'">
                                        <span class="text-gray-500 dark:text-gray-400">{{ formatDate(ticket.closed_at) }}</span>
                                    </template>
                                    <span v-else :class="ageClass(edad(ticket).nivel)">{{ edad(ticket).texto }}</span>
                                </td>
                                <td class="px-2 py-3 text-right">
                                    <button
                                        v-if="canEdit"
                                        @click.stop="router.push(`/support/${ticket.id}/edit`)"
                                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-green-700 dark:hover:bg-gray-600"
                                        title="Editar"
                                    >
                                        <icon-lucide-pencil class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards: lo que necesita el técnico en la calle. -->
                <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
                    <div v-for="ticket in tickets" :key="ticket.id" class="p-4" @click="abrir(ticket)">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    #{{ ticket.id }} ·
                                    <span :class="ageClass(edad(ticket).nivel)">{{ pestana === 'closed' ? formatDate(ticket.closed_at) : edad(ticket).texto }}</span>
                                </p>
                                <h3 class="text-sm font-semibold leading-snug text-gray-800 dark:text-white">
                                    {{ ticket.subject }}
                                    <span v-if="ticket.priority === 'urgent' || ticket.priority === 'high'" :class="priorityBadgeClass(ticket.priority)" class="ml-1">
                                        {{ ticket.priority_label }}
                                    </span>
                                </h3>
                                <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ nombreCliente(ticket) }}</p>
                                <p v-if="ticket.customer_address" class="text-xs text-gray-500 dark:text-gray-400">
                                    <v-icon name="md-locationon" class="inline h-3 w-3" /> {{ ticket.customer_address }}
                                </p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Técnico: {{ ticket.staff ? `${ticket.staff.user_name} ${ticket.staff.user_lastname}` : 'Sin asignar' }}
                                </p>
                            </div>
                            <span :class="groupBadgeClass(ticket.status_group)" class="shrink-0">{{ ticket.status_label }}</span>
                        </div>

                        <div class="mt-3 flex gap-2">
                            <button
                                @click.stop="abrir(ticket)"
                                class="flex-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300"
                            >
                                Abrir
                            </button>
                            <a
                                v-if="telUrl(ticket.user?.tel)"
                                :href="telUrl(ticket.user?.tel)"
                                @click.stop
                                class="flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200"
                            >
                                <v-icon name="md-phone" class="h-4 w-4" /> Llamar
                            </a>
                            <a
                                v-if="whatsappUrl(ticket.user?.tel)"
                                :href="whatsappUrl(ticket.user?.tel)"
                                target="_blank"
                                rel="noopener"
                                @click.stop
                                class="flex items-center gap-1 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs font-semibold text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300"
                            >
                                <v-icon name="bi-whatsapp" class="h-4 w-4" /> WhatsApp
                            </a>
                        </div>
                    </div>
                </div>

                <div v-if="!loading && tickets.length === 0" class="p-10 text-center text-gray-500 dark:text-gray-400" data-testid="board-empty">
                    <template v-if="hayFiltros">No hay tickets con esos filtros.</template>
                    <template v-else>{{ VACIO[pestana] }}</template>
                </div>

                <!-- Paginación -->
                <div
                    v-if="ultimaPagina > 1"
                    class="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-700"
                >
                    <button
                        :disabled="pagina <= 1 || loading"
                        @click="irAPagina(pagina - 1)"
                        class="rounded-lg px-3 py-1.5 text-sm text-gray-600 transition hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Anterior
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Página {{ pagina }} de {{ ultimaPagina }} · {{ total }} tickets
                    </span>
                    <button
                        :disabled="pagina >= ultimaPagina || loading"
                        @click="irAPagina(pagina + 1)"
                        class="rounded-lg px-3 py-1.5 text-sm text-gray-600 transition hover:bg-gray-100 disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Siguiente
                    </button>
                </div>
            </div>
        </template>

        <!-- ── PR C · Expedientes archivados ────────────────────────────────
             Sólo para quien puede archivar o restaurar. Paginado en el servidor. -->
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
                                <th class="th">ID</th>
                                <th class="th">Asunto</th>
                                <th class="th">Cliente</th>
                                <th class="th">Estado al archivar</th>
                                <th class="th">Motivo</th>
                                <th class="th">Archivado por</th>
                                <th class="th">Fecha</th>
                                <th class="th text-center">Acciones</th>
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
                                    <span :class="groupBadgeClass(statusGroup(t.status))">{{ statusLabel(t.status) }}</span>
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
import TicketSubjectsManager from '../components/TicketSubjectsManager.vue'
import {
    groupBadgeClass, groupDotClass, priorityBadgeClass,
    ageInfo, ageClass, telUrl, whatsappUrl,
} from '@/utils/ticketStyles'

const { can } = usePermissions()

const router = useRouter()
const toast = ref(null)

// ── La cola (bitácora § 133) ─────────────────────────────────────────────
//
// Antes se pedían TODOS los tickets —abiertos y cerrados, con sus notas y
// adjuntos— y se filtraba en el navegador. Ahora el servidor pagina, filtra
// por pestaña y cuenta cuántos hay en cada una en una sola consulta.

const PESTANAS = [
    { key: 'open', label: 'Abiertos', icono: 'hi-ticket' },
    { key: 'mine', label: 'Mis tickets', icono: 'bi-person' },
    { key: 'unassigned', label: 'Sin asignar', icono: 'md-personoff' },
    { key: 'waiting', label: 'En espera', grupo: 'en_espera' },
    { key: 'to_close', label: 'Para cerrar', grupo: 'para_cerrar' },
    { key: 'closed', label: 'Cerrados', grupo: 'cerrado' },
]

const VACIO = {
    open: 'No hay tickets abiertos. 🎉',
    mine: 'No tienes tickets asignados abiertos.',
    unassigned: 'Todos los tickets abiertos tienen técnico.',
    waiting: 'Ningún ticket está en espera.',
    to_close: 'No hay tickets esperando cierre.',
    closed: 'Todavía no hay tickets cerrados.',
}

// La pestaña elegida se recuerda por navegador (comodidad, no dato): si el
// almacenamiento no está disponible, se empieza en «Abiertos».
const CLAVE_PESTANA = 'ispwatch.support.tab'
const leerPestana = () => {
    try {
        const guardada = localStorage.getItem(CLAVE_PESTANA)
        return PESTANAS.some((t) => t.key === guardada) ? guardada : 'open'
    } catch {
        return 'open'
    }
}

const pestana = ref(leerPestana())
const tickets = ref([])
const contadores = ref({})
const pagina = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')
const searchQuery = ref('')
const orden = ref('urgency')
const filters = ref({
    subject: 'all',
    priority: 'all',
    staff: 'all',
})

const hayFiltros = computed(() =>
    !!searchQuery.value.trim()
    || filters.value.subject !== 'all'
    || filters.value.priority !== 'all'
    || filters.value.staff !== 'all',
)

// Respuestas viejas que llegan tarde no pisan a la nueva.
let peticion = 0

const cargarTablero = async () => {
    const mia = ++peticion
    loading.value = true
    error.value = ''

    const params = { tab: pestana.value, page: pagina.value, per_page: 25, sort: orden.value }
    if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
    if (filters.value.subject !== 'all') params.subject_id = filters.value.subject
    if (filters.value.priority !== 'all') params.priority = filters.value.priority
    if (filters.value.staff !== 'all') params.staff_id = filters.value.staff

    try {
        const { data } = await api.support.getBoard(params)
        if (mia !== peticion) return
        tickets.value = data.data ?? []
        contadores.value = data.counts ?? {}
        pagina.value = data.current_page ?? 1
        ultimaPagina.value = data.last_page ?? 1
        total.value = data.total ?? 0
    } catch (err) {
        if (mia !== peticion) return
        console.error('Error al cargar tickets:', err)
        error.value = 'No se pudieron cargar los tickets.'
    } finally {
        if (mia === peticion) loading.value = false
    }
}

const cambiarPestana = (key) => {
    if (pestana.value === key) return
    pestana.value = key
    try { localStorage.setItem(CLAVE_PESTANA, key) } catch { /* sin almacenamiento */ }
    pagina.value = 1
    cargarTablero()
}

const irAPagina = (n) => {
    pagina.value = n
    cargarTablero()
}

const abrir = (ticket) => router.push(`/support/${ticket.id}`)

const nombreCliente = (t) => {
    const n = `${t.user?.user_name ?? ''} ${t.user?.user_lastname ?? ''}`.trim()
    return n || 'Cliente eliminado'
}

// La edad se recalcula al cargar; no hace falta un reloj por fila.
const ahora = ref(Date.now())
const edad = (t) => ageInfo(t.created_at, ahora.value)

// ── Asuntos frecuentes ───────────────────────────────────────────────────
//
// El filtro usa la lista COMPLETA, desactivados incluidos: un asunto retirado
// ya no se ofrece al radicar, pero sus tickets viejos se siguen encontrando.
const canManageSubjects = computed(() => can('ticket_manage_catalogs'))
const gestorAsuntosAbierto = ref(false)
const asuntosParaFiltrar = ref([])

const cargarAsuntos = async () => {
    try {
        const { data } = await api.support.getSubjects()
        asuntosParaFiltrar.value = data
    } catch (err) {
        console.error('Error al cargar los asuntos:', err)
    }
}

const alCambiarAsuntos = () => {
    cargarAsuntos()
    cargarCatalogos(true)
}

// PR B · Capacidades separadas: la acción se OCULTA si falta el permiso.
const canCreate = computed(() => can('ticket_create'))
const canEdit = computed(() => can('ticket_edit'))

const staffList = ref([])

const loadStaff = async () => {
    try {
        // `/support/staff-options` y no `/staff` (que exige `view_staff`):
        // técnico es quien puede registrar intervenciones (P-56).
        const response = await api.support.getStaffOptions()
        const allUsers = response.data.data || []
        staffList.value = allUsers.filter(user => user.can_intervene)
    } catch (err) {
        console.error('Error loading staff:', err)
    }
}

// R2: las ETIQUETAS vienen del catálogo; los COLORES, de utils/ticketStyles.
const {
    priorities,
    cargar: cargarCatalogos,
    statusLabel, statusGroup, priorityLabel,
} = useTicketCatalogs()

const formatDate = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleDateString('es-CO', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
}

// ── PR C · Expedientes archivados ────────────────────────────────────────

const vistaArchivados = ref(false)
const archivados = ref([])
const cargandoArchivados = ref(false)
const archivadosPagina = ref(1)
const archivadosPaginas = ref(1)
const archivadosTotal = ref(0)

const canSeeArchived = computed(() => can('ticket_archive') || can('ticket_restore'))
const canRestore = computed(() => can('ticket_restore'))

const alternarVista = () => {
    vistaArchivados.value = !vistaArchivados.value

    if (vistaArchivados.value) {
        cargarArchivados(1)
    } else {
        cargarTablero()
    }
}

const cargarArchivados = async (numero = 1) => {
    try {
        cargandoArchivados.value = true

        const params = { page: numero }

        if (searchQuery.value.trim()) params.search = searchQuery.value.trim()
        if (filters.value.priority !== 'all') params.priority = filters.value.priority
        if (filters.value.subject !== 'all') params.subject_id = filters.value.subject

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

const recargar = () => {
    if (vistaArchivados.value) {
        cargarArchivados(1)
    } else {
        pagina.value = 1
        cargarTablero()
    }
}

// Filtros y orden: al instante. La búsqueda espera a que se deje de teclear.
watch([filters, orden], recargar, { deep: true })

let esperaBusqueda = null
watch(searchQuery, () => {
    clearTimeout(esperaBusqueda)
    esperaBusqueda = setTimeout(recargar, 350)
})

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

        await cargarArchivados(archivadosPagina.value)
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
    cargarAsuntos()
    loadStaff()
    cargarTablero()
})
</script>

<style scoped>
.th {
    @apply px-4 py-3 text-left text-xs font-medium uppercase text-gray-600 dark:text-gray-300;
}
.filtro {
    @apply w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm;
}
</style>
