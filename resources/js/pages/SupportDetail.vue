<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
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
                        Editar
                    </button>
                    <button
                        @click="router.push('/support')"
                        class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition"
                    >
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

                    <!-- Archivos Adjuntos -->
                    <div v-if="ticket.attachments?.length > 0" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Archivos Adjuntos</h2>
                        <div class="space-y-2">
                            <div v-for="attachment in ticket.attachments" :key="attachment.id"
                                 class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <v-icon name="pr-file" class="w-5 h-5 text-gray-500" />
                                    <span class="text-sm text-gray-800 dark:text-white">{{ attachment.file_name }}</span>
                                </div>
                                <a :href="attachment.url" target="_blank"
                                   class="text-blue-600 hover:text-blue-700 text-sm">
                                    Descargar
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Mensajes -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Conversación</h2>
                        
                        <div v-if="ticket.messages?.length > 0" class="space-y-4 mb-6">
                            <div v-for="message in ticket.messages" :key="message.id"
                                 class="p-4 rounded-lg"
                                 :class="message.user_id === ticket.user_id ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-gray-50 dark:bg-gray-700'">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="font-semibold text-gray-800 dark:text-white">
                                        {{ message.user?.user_name }} {{ message.user?.user_lastname }}
                                    </span>
                                    <span class="text-xs text-gray-500">{{ formatDate(message.created_at) }}</span>
                                </div>
                                <p class="text-gray-600 dark:text-gray-300">{{ message.message }}</p>
                            </div>
                        </div>

                        <!-- Nuevo mensaje -->
                        <div class="border-t dark:border-gray-700 pt-4">
                            <textarea
                                v-model="newMessage"
                                rows="3"
                                placeholder="Escribe tu mensaje..."
                                class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                            ></textarea>
                            <button
                                @click="sendMessage"
                                :disabled="!newMessage.trim()"
                                class="mt-2 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition disabled:opacity-50"
                            >
                                Enviar Mensaje
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
                        </div>
                    </div>

                    <!-- Info del Staff -->
                    <div v-if="ticket.staff" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Asignado a</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ ticket.staff?.user_name }} {{ ticket.staff?.user_lastname }}
                        </p>
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
import { hasPermission } from '../services/auth'

const router = useRouter()
const route = useRoute()
const ticketId = route.params.id

const canEdit = computed(() => hasPermission('support.update'))

const ticket = ref({})
const loading = ref(true)
const newMessage = ref('')

const loadTicket = async () => {
    try {
        loading.value = true
        const response = await api.support.getOne(ticketId)
        ticket.value = response.data
    } catch (err) {
        console.error('Error al cargar ticket:', err)
        alert('Error al cargar el ticket.')
        router.push('/support')
    } finally {
        loading.value = false
    }
}

const sendMessage = async () => {
    if (!newMessage.value.trim()) return

    try {
        await api.support.addMessage(ticketId, newMessage.value)
        newMessage.value = ''
        loadTicket() // Recargar para mostrar el nuevo mensaje
    } catch (err) {
        console.error('Error al enviar mensaje:', err)
        alert('Error al enviar el mensaje.')
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
    loadTicket()
})
</script>
