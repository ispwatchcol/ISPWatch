<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Estadísticas de Soporte</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Métricas y análisis de tickets</p>
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

            <!-- Tiempo Promedio de Resolución -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-6 border border-gray-100 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2">Tiempo Promedio de Resolución</h3>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ stats.avg_resolution_time }} días</p>
            </div>

            <!-- Distribuciones -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Por Estado -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Estado</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_status" :key="item.status" class="flex justify-between items-center">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.status }}</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-white">{{ item.count }}</span>
                        </div>
                    </div>
                </div>

                <!-- Por Prioridad -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Prioridad</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_priority" :key="item.priority" class="flex justify-between items-center">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ item.priority }}</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-white">{{ item.count }}</span>
                        </div>
                    </div>
                </div>

                <!-- Por Categoría -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 border border-gray-100 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Por Categoría</h3>
                    <div class="space-y-2">
                        <div v-for="item in stats.by_category" :key="item.category" class="flex justify-between items-center">
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
                                <td class="px-6 py-4 text-sm">{{ ticket.status }}</td>
                                <td class="px-6 py-4 text-sm">{{ ticket.priority }}</td>
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
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const stats = ref({
    total_tickets: 0,
    open_tickets: 0,
    in_progress_tickets: 0,
    resolved_this_month: 0,
    avg_resolution_time: 0,
    by_status: [],
    by_priority: [],
    by_category: [],
    monthly_trend: [],
    recent_tickets: []
})

const loading = ref(true)

const loadStatistics = async () => {
    try {
        loading.value = true
        const response = await api.support.getStatistics()
        stats.value = response.data
    } catch (err) {
        console.error('Error al cargar estadísticas:', err)
        alert('Error al cargar las estadísticas.')
    } finally {
        loading.value = false
    }
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('es-ES', {
        year: 'numeric', month: 'short', day: 'numeric'
    })
}

onMounted(() => {
    loadStatistics()
})
</script>
