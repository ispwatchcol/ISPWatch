<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
        <button
            @click="router.push({ name: 'Customers' })"
            class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white transition"
        >
            <icon-mdi-arrow-left class="w-6 h-6" />
        </button>
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Editar Cliente</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Modifica los datos del cliente</p>
        </div>
        </div>

        <!-- Loading -->
        <div v-if="loadingData" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando datos...</p>
        </div>

        <!-- Formulario -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 max-w-7xl mx-auto border border-gray-100 dark:border-gray-700">
        <form @submit.prevent="handleSubmit">
            
            <!-- Sección: Datos del Usuario -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Datos de Acceso
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Email -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Email</label>
                <input
                    v-model="form.email"
                    type="email"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>

                <!-- Contraseña (opcional en edición) -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">
                    Nueva Contraseña (opcional)
                </label>
                <input
                    v-model="form.password"
                    type="password"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                    placeholder="Dejar vacío para no cambiar"
                />
                </div>

                <!-- Teléfono -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Teléfono</label>
                <input
                    v-model="form.tel"
                    type="tel"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>

                <!-- Email Tenant -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Email Tenant</label>
                <input
                    v-model="form.email_tenant"
                    type="email"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>
            </div>
            </div>

            <!-- Sección: Datos del Perfil -->
            <div class="mb-8">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 border-b border-gray-200 dark:border-gray-700 pb-2">
                Información del Cliente
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Nombre -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Nombre</label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>

                <!-- Apellido -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Apellido</label>
                <input
                    v-model="form.last_name"
                    type="text"
                    required
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>

                <!-- Departamento -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Departamento</label>
                <input
                    v-model="form.department"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>

                <!-- Posición -->
                <div>
                <label class="block text-gray-700 dark:text-gray-300 font-medium mb-2">Posición</label>
                <input
                    v-model="form.position"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-gray-700 text-gray-800 dark:text-white px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
                />
                </div>
            </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
            </div>

            <!-- Botones -->
            <div class="flex gap-4">
            <button
                type="submit"
                :disabled="loading"
                class="flex-1 bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 disabled:bg-gray-400 dark:disabled:bg-gray-600 text-white py-3 rounded-lg font-medium transition"
            >
                {{ loading ? 'Guardando...' : 'Actualizar Cliente' }}
            </button>
            <button
                type="button"
                @click="router.push({ name: 'Customers' })"
                class="px-8 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-3 rounded-lg transition"
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

const router = useRouter()
const route = useRoute()

const form = ref({
    email: '',
    password: '',
    tel: '',
    email_tenant: '',
    name: '',
    last_name: '',
    department: '',
    position: ''
})

const loading = ref(false)
const loadingData = ref(true)
const error = ref('')

const loadCustomer = async () => {
    try {
        const response = await api.customers.getOne(route.params.id)
        form.value = {
            name: response.data.name,
            last_name: response.data.last_name,
            department: response.data.department || '',
            position: response.data.position || '',
            email: response.data.email,
            tel: response.data.tel || '',
            email_tenant: response.data.email_tenant || '',
            password: ''
        }
    } catch (err) {
        console.error('Error al cargar cliente:', err)
        error.value = 'Error al cargar los datos del cliente. Por favor, intenta nuevamente.'
    } finally {
        loadingData.value = false
    }
}

const handleSubmit = async () => {
    loading.value = true
    error.value = ''

    try {
        // dont send empty password
        const dataToSend = { ...form.value }
        if (!dataToSend.password) {
            delete dataToSend.password
        }

        await api.customers.update(route.params.id, dataToSend)
        alert('Cliente actualizado exitosamente')
        router.push('/customers')
    } catch (err) {
        console.error('Error al actualizar cliente:', err)
        error.value = err.response?.data?.message || 'Error al actualizar el cliente. Por favor, intenta nuevamente.'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    loadCustomer()
})
</script>