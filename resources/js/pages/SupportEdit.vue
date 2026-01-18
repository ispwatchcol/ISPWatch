<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Editar Ticket #{{ ticketId }}</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Actualiza la información del ticket</p>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="max-w-3xl mx-auto rounded-xl p-8">
            <div class="text-center py-8">
                <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
                <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando ticket...</p>
            </div>
        </div>

        <!-- Formulario -->
        <div v-else class="max-w-3xl mx-auto bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 border border-gray-100 dark:border-gray-700">
            <form @submit.prevent="handleSubmit">
                <!-- Asunto -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Asunto <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.subject"
                        type="text"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors.subject }"
                    />
                    <p v-if="errors.subject" class="mt-1 text-sm text-red-500">{{ errors.subject }}</p>
                </div>

                <!-- Descripción -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Descripción
                    </label>
                    <textarea
                        v-model="form.description"
                        rows="5"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    ></textarea>
                </div>

                <!-- Categoría -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Categoría <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.category"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="technical">Técnico</option>
                        <option value="billing">Facturación</option>
                        <option value="services">Servicios</option>
                        <option value="general">General</option>
                    </select>
                </div>

                <!-- Prioridad (solo admin) -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Prioridad <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.priority"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="low">Baja</option>
                        <option value="medium">Media</option>
                        <option value="high">Alta</option>
                        <option value="urgent">Urgente</option>
                    </select>
                </div>

                <!-- Estado -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Estado <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.status"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="open">Abierto</option>
                        <option value="in_progress">En Progreso</option>
                        <option value="resolved">Resuelto</option>
                        <option value="closed">Cerrado</option>
                    </select>
                </div>

                <!-- Asignar Staff -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Asignado a
                    </label>
                    <select
                        v-model="form.staff_id"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="">Sin asignar</option>
                        <option v-for="member in staffList" :key="member.id" :value="member.id">
                            {{ member.user_name }} {{ member.user_lastname }} ({{ member.email }})
                        </option>
                    </select>
                </div>

                <!-- Botones -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span v-if="!submitting">Actualizar Ticket</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <div class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                            Actualizando...
                        </span>
                    </button>
                    <button
                        type="button"
                        @click="router.push(`/support/${ticketId}`)"
                        class="px-6 py-3 rounded-lg font-medium border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import NotificationToast from '../components/NotificationToast.vue'

const router = useRouter()
const route = useRoute()
const ticketId = route.params.id

const form = ref({
    subject: '',
    description: '',
    category: '',
    priority: '',
    status: ''
})

const staffList = ref([])
const loading = ref(true)
const errors = ref({})
const submitting = ref(false)
const toast = ref(null)

const loadTicket = async () => {
    try {
        loading.value = true
        // Cargar ticket y staff en paralelo
        const [ticketRes, staffRes] = await Promise.all([
            api.support.getOne(ticketId),
            api.staff.getAll()
        ])

        const ticket = ticketRes.data
        // Filtrar solo usuarios con rol de Staff (role_id === 2)
        const allUsers = staffRes.data.data || []
        staffList.value = allUsers.filter(user => user.role_id === 2)
        
        form.value = {
            subject: ticket.subject,
            description: ticket.description || '',
            category: ticket.category,
            priority: ticket.priority,
            status: ticket.status,
            staff_id: ticket.staff_id || ''
        }
    } catch (err) {
        console.error('Error al cargar ticket:', err)
        toast.value?.error('Error', 'Error al cargar los detalles del ticket.')
        router.push('/support')
    } finally {
        loading.value = false
    }
}

const validate = () => {
    errors.value = {}

    if (!form.value.subject || form.value.subject.trim() === '') {
        errors.value.subject = 'El asunto es requerido'
    }

    if (!form.value.category) {
        errors.value.category = 'La categoría es requerida'
    }

    return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
    if (!validate()) return

    try {
        submitting.value = true

        await api.support.update(ticketId, form.value)

        toast.value?.success('Éxito', 'Ticket actualizado correctamente.')
        setTimeout(() => router.push(`/support/${ticketId}`), 1500)
    } catch (err) {
        console.error('Error al actualizar ticket:', err)
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors
            toast.value?.error('Error', 'Por favor revisa los campos del formulario.')
        } else {
            toast.value?.error('Error', 'No se pudo actualizar el ticket. Intenta de nuevo.')
        }
    } finally {
        submitting.value = false
    }
}

onMounted(() => {
    loadTicket()
})
</script>
