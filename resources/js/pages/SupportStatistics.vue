<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <!-- Header -->
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Estadísticas de Soporte</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Métricas y análisis de tickets · horas de {{ stats.timezone || 'America/Bogota' }}
                </p>
            </div>
            <!-- La ruta ya exige `ticket_export`: quien ve el tablero puede exportar. -->
            <button
                @click="exportar"
                :disabled="exportando"
                class="flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-white transition hover:bg-blue-700 disabled:opacity-60 sm:w-auto sm:px-6"
            >
                <v-icon name="md-download" class="h-4 w-4" />
                {{ exportando ? 'Generando…' : 'Exportar CSV' }}
            </button>
        </div>

        <!-- Filtros: los MISMOS para el tablero y el archivo. -->
        <div class="mb-6 rounded-xl border border-gray-100 bg-white p-4 shadow-md dark:border-gray-700 dark:bg-gray-800">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Radicado desde
                    <input v-model="filtros.created_from" type="date" :class="campo" />
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Radicado hasta
                    <input v-model="filtros.created_to" type="date" :class="campo" />
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Router
                    <select v-model="filtros.router_id" :class="campo">
                        <option value="">Todos</option>
                        <option v-for="r in opciones.routers" :key="r.id" :value="String(r.id)">{{ r.name }}</option>
                    </select>
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Sectorial / nodo
                    <select v-model="filtros.sectorial_id" :class="campo">
                        <option value="">Todos</option>
                        <option v-for="s in opciones.sectorials" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </select>
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Estado
                    <select v-model="filtros.status" :class="campo">
                        <option value="">Todos</option>
                        <option v-for="s in statuses" :key="s.code" :value="s.code">{{ s.label }}</option>
                    </select>
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Prioridad
                    <select v-model="filtros.priority" :class="campo">
                        <option value="">Todas</option>
                        <option v-for="p in priorities" :key="p.code" :value="p.code">{{ p.label }}</option>
                    </select>
                </label>
                <label class="text-sm text-gray-600 dark:text-gray-300">
                    Categoría
                    <select v-model="filtros.category" :class="campo">
                        <option value="">Todas</option>
                        <option v-for="c in categories" :key="c.code" :value="c.code">{{ c.label }}</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button @click="loadStatistics" :class="botonSecundario" class="flex-1">Aplicar</button>
                    <button @click="limpiar" :class="botonSecundario">Limpiar</button>
                </div>
            </div>
            <p v-if="errorFiltros" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ errorFiltros }}</p>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando estadísticas...</p>
        </div>

        <div v-else>
            <!-- Cards de Métricas -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total de Tickets</h3>
                    <p class="text-3xl font-bold text-gray-800 dark:text-white mt-2">{{ stats.total_tickets }}</p>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Tickets Abiertos</h3>
                    <p class="text-3xl font-bold text-orange-600 dark:text-orange-400 mt-2">{{ stats.open_tickets }}</p>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">En Progreso</h3>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ stats.in_progress_tickets }}</p>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Resueltos (Este Mes)</h3>
                    <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ stats.resolved_this_month }}</p>
                </div>
            </div>

            <!-- Tiempos: la distribución, no sólo el promedio (§ 17). -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md mb-6 border border-gray-100 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">Tiempos de atención (horas)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Desde el radicado. El P90 dice cuánto tardó el ticket que deja atrás al 90 % de los demás:
                        es lo que el promedio esconde cuando hay casos muy largos.
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700 text-xs uppercase text-gray-600 dark:text-gray-300">
                            <tr>
                                <th class="px-6 py-3 text-left">Tiempo</th>
                                <th class="px-6 py-3 text-right">Tickets</th>
                                <th class="px-6 py-3 text-right">Promedio</th>
                                <th class="px-6 py-3 text-right">Mediana</th>
                                <th class="px-6 py-3 text-right">P90</th>
                                <th class="px-6 py-3 text-right">P95</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-800 dark:text-white">
                            <tr v-for="fila in filasDeTiempo" :key="fila.nombre">
                                <td class="px-6 py-3">{{ fila.nombre }}</td>
                                <td class="px-6 py-3 text-right">{{ fila.datos?.count ?? 0 }}</td>
                                <td class="px-6 py-3 text-right">{{ horas(fila.datos?.avg_hours) }}</td>
                                <td class="px-6 py-3 text-right font-semibold">{{ horas(fila.datos?.median_hours) }}</td>
                                <td class="px-6 py-3 text-right font-semibold">{{ horas(fila.datos?.p90_hours) }}</td>
                                <td class="px-6 py-3 text-right font-semibold">{{ horas(fila.datos?.p95_hours) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="descartados" class="px-6 py-3 text-xs text-amber-700 dark:text-amber-400">
                    {{ descartados }} ticket(s) con fecha de resolución o cierre anterior al radicado no se cuentan.
                </p>
            </div>

            <!-- Distribuciones -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Estado</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_status" :key="item.code" class="flex justify-between items-center">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.status }}</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-white">{{ item.count }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Prioridad</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_priority" :key="item.code" class="flex justify-between items-center">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.priority }}</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-white">{{ item.count }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Categoría</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_category" :key="item.code" class="flex justify-between items-center">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.category }}</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-white">{{ item.count }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tickets Recientes -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">Tickets Recientes</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Asunto</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Estado</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Prioridad</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="ticket in stats.recent_tickets" :key="ticket.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer" @click="router.push(`/support/${ticket.id}`)">
                                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">#{{ ticket.id }}</td>
                                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ ticket.subject }}</td>
                                <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200">{{ ticket.status_label || ticket.status }}</td>
                                <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-200">{{ ticket.priority_label || ticket.priority }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ formatDate(ticket.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import { useNotifications } from '@/composables/useNotifications'
import { downloadBlob, filenameFromResponse } from '@/utils/download'

const router = useRouter()
const toast = useNotifications()
const { statuses, priorities, categories, cargar: cargarCatalogos } = useTicketCatalogs()

// Zona de presentación (F1-20). La fija el servidor; ésta es sólo el respaldo
// mientras llega la primera respuesta.
const ZONA = 'America/Bogota'

const campo = 'mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white'
const botonSecundario = 'rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'

const filtrosVacios = () => ({
    created_from: '', created_to: '', router_id: '', sectorial_id: '',
    status: '', priority: '', category: '',
})
const filtros = ref(filtrosVacios())

const stats = ref({
    total_tickets: 0,
    open_tickets: 0,
    in_progress_tickets: 0,
    resolved_this_month: 0,
    avg_resolution_time: 0,
    resolution_time: null,
    closure_time: null,
    by_status: [],
    by_priority: [],
    by_category: [],
    monthly_trend: [],
    recent_tickets: [],
    timezone: ZONA,
    filter_options: { routers: [], sectorials: [] },
})

const loading = ref(true)
const exportando = ref(false)
const errorFiltros = ref('')

const opciones = computed(() => stats.value.filter_options || { routers: [], sectorials: [] })

const filasDeTiempo = computed(() => [
    { nombre: 'Hasta la resolución', datos: stats.value.resolution_time },
    { nombre: 'Hasta el cierre', datos: stats.value.closure_time },
])

const descartados = computed(() =>
    (stats.value.resolution_time?.excluded || 0) + (stats.value.closure_time?.excluded || 0)
)

// Sólo viajan los filtros con valor: un `router_id=` vacío no es «todos», es
// un entero inválido para el validador.
const parametros = () => Object.fromEntries(
    Object.entries(filtros.value).filter(([, v]) => v !== '' && v !== null)
)

const mensajeDeError = (err, porDefecto) =>
    Object.values(err?.response?.data?.errors || {})[0]?.[0] || porDefecto

const loadStatistics = async () => {
    errorFiltros.value = ''
    try {
        loading.value = true
        const response = await api.support.getStatistics(parametros())
        stats.value = response.data
    } catch (err) {
        console.error('Error al cargar estadísticas:', err)
        if (err?.response?.status === 422) {
            errorFiltros.value = mensajeDeError(err, 'Revisa los filtros.')
        }
    } finally {
        loading.value = false
    }
}

const limpiar = () => {
    filtros.value = filtrosVacios()
    loadStatistics()
}

const exportar = async () => {
    exportando.value = true
    try {
        const res = await api.support.exportTickets(parametros())
        downloadBlob(res.data, filenameFromResponse(res, 'tickets.csv'))
    } catch (err) {
        console.error('Error exportando tickets:', err)
        // El cuerpo de un error llega como Blob por `responseType: 'blob'`.
        let mensaje = 'No se pudo generar la exportación.'
        if (err?.response?.status === 422 && err.response.data instanceof Blob) {
            try {
                mensaje = mensajeDeError({ response: { data: JSON.parse(await err.response.data.text()) } }, mensaje)
            } catch { /* se queda el mensaje genérico */ }
        }
        toast.error('Exportación', mensaje)
    } finally {
        exportando.value = false
    }
}

const horas = (valor) => (valor === null || valor === undefined)
    ? '—'
    : Number(valor).toLocaleString('es-CO', { maximumFractionDigits: 2 })

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleString('es-CO', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
        timeZone: stats.value.timezone || ZONA,
    })
}

onMounted(() => {
    cargarCatalogos()
    loadStatistics()
})
</script>
