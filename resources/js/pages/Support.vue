<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Soporte</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Gestión de tickets de soporte</p>
            </div>
            <button
                v-if="canCreate"
                @click="router.push('/support/create')"
                class="bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white px-6 py-3 rounded-lg flex items-center gap-2 transition"
            >
                <icon-lucide-plus class="w-4 h-4" />
                Nuevo Ticket
            </button>
        </div>

        <!-- Buscador y Filtros -->
        <div class="mb-6 space-y-4">
            <!-- Buscador -->
            <div class="relative max-w-md">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por asunto o descripción..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                <v-icon name="io-search" class="absolute left-3 top-3.5 w-5 h-5 text-gray-400" />
                <button
                    v-if="searchQuery"
                    @click="searchQuery = ''"
                    class="absolute right-3 top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                    <v-icon name="io-close-circle" class="w-6 h-6" />
                </button>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap gap-3">
                <select
                    v-model="filters.status"
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Todos los estados</option>
                    <option value="open">Abierto</option>
                    <option value="in_progress">En Progreso</option>
                    <option value="resolved">Resuelto</option>
                    <option value="closed">Cerrado</option>
                </select>

                <select
                    v-model="filters.priority"
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Todas las prioridades</option>
                    <option value="low">Baja</option>
                    <option value="medium">Media</option>
                    <option value="high">Alta</option>
                    <option value="urgent">Urgente</option>
                </select>

                <select
                    v-model="filters.category"
                    class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Todas las categorías</option>
                    <option value="technical">Técnico</option>
                    <option value="billing">Facturación</option>
                    <option value="services">Servicios</option>
                    <option value="general">General</option>
                </select>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando tickets...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
        </div>

        <!-- Tabla de tickets -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">ID</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Asunto</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Cliente</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Categoría</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Estado</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Prioridad</th>
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
                                <span :class="getCategoryBadgeClass(ticket.category)">
                                    {{ getCategoryLabel(ticket.category) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span :class="getStatusBadgeClass(ticket.status)">
                                    {{ getStatusLabel(ticket.status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <span :class="getPriorityBadgeClass(ticket.priority)">
                                    {{ getPriorityLabel(ticket.priority) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                {{ formatDate(ticket.created_at) }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2">
                                    <button
                                        @click="router.push(`/support/${ticket.id}`)"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                            bg-blue-50 text-blue-700 border border-blue-200
                                            hover:bg-blue-100 hover:scale-[1.03] transition-all
                                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                                    >
                                        <icon-lucide-eye class="w-4 h-4" />
                                        Ver
                                    </button>
                                    <button
                                        v-if="canEdit"
                                        @click="router.push(`/support/${ticket.id}/edit`)"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                            bg-green-50 text-green-700 border border-green-200
                                            hover:bg-green-100 hover:scale-[1.03] transition-all
                                            dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                                    >
                                        <icon-lucide-pencil class="w-4 h-4" />
                                        Editar
                                    </button>
                                    <button
                                        v-if="canDelete"
                                        @click="deleteTicket(ticket.id)"
                                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                            bg-red-50 text-red-700 border border-red-200
                                            hover:bg-red-100 hover:scale-[1.03] transition-all
                                            dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                                    >
                                        <icon-lucide-trash-2 class="w-4 h-4" />
                                        Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="filteredTickets.length === 0 && !loading">
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                {{ searchQuery ? 'No se encontraron resultados' : 'No hay tickets registrados' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { hasPermission } from '../services/auth'

const router = useRouter()

const tickets = ref([])
const loading = ref(true)
const error = ref('')
const searchQuery = ref('')
const filters = ref({
    status: 'all',
    priority: 'all',
    category: 'all'
})

const canCreate = computed(() => hasPermission('support.create'))
const canEdit = computed(() => hasPermission('support.update'))
const canDelete = computed(() => hasPermission('support.delete'))

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

    return result
})

const loadTickets = async () => {
    try {
        loading.value = true
        const response = await api.support.getAll()
        tickets.value = response.data
    } catch (err) {
        console.error('Error al cargar tickets:', err)
        error.value = 'Error al cargar los tickets.'
    } finally {
        loading.value = false
    }
}

const deleteTicket = async (id) => {
    if (!confirm('¿Estás seguro de eliminar este ticket?')) return

    try {
        await api.support.delete(id)
        alert('Ticket eliminado correctamente.')
        loadTickets()
    } catch (err) {
        console.error('Error al eliminar ticket:', err)
        alert('Error al eliminar el ticket.')
    }
}

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

const getStatusLabel = (status) => {
    const labels = { open: 'Abierto', in_progress: 'En Progreso', resolved: 'Resuelto', closed: 'Cerrado' }
    return labels[status] || status
}

const getPriorityBadgeClass = (priority) => {
    const classes = {
        low: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        medium: 'px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
        high: 'px-3 py-1 rounded-full text-xs font-semibold bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        urgent: 'px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
    }
    return classes[priority] || classes.medium
}

const getPriorityLabel = (priority) => {
    const labels = { low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' }
    return labels[priority] || priority
}

const getCategoryBadgeClass = (category) => {
    const classes = {
        technical: 'px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
        billing: 'px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
        services: 'px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
        general: 'px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    }
    return classes[category] || classes.general
}

const getCategoryLabel = (category) => {
    const labels = { technical: 'Técnico', billing: 'Facturación', services: 'Servicios', general: 'General' }
    return labels[category] || category
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
}

onMounted(() => {
    loadTickets()
})
</script>
