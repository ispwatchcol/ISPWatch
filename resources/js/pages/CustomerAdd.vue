<template>
    <div class="min-h-screen bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
        <button
            @click="router.push('/customers')"
            class="text-gray-400 hover:text-white transition"
        >
            <icon-mdi-arrow-left class="w-6 h-6" />
        </button>
        <div>
            <h1 class="text-3xl font-bold text-white">Nuevo Cliente</h1>
            <p class="text-gray-400 mt-1">Registra un nuevo perfil de cliente</p>
        </div>
        </div>

        <!-- Formulario -->
        <div class="bg-gray-800 rounded-lg shadow-xl p-6 max-w-2xl">
            <form @submit.prevent="handleSubmit">
                <!-- Nombre -->
                <div class="mb-4">
                <label class="block text-gray-300 font-medium mb-2">Nombre</label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Juan"
                />
                </div>

                <!-- Apellido -->
                <div class="mb-4">
                <label class="block text-gray-300 font-medium mb-2">Apellido</label>
                <input
                    v-model="form.last_name"
                    type="text"
                    required
                    class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Pérez"
                />
                </div>

                <!-- Departamento -->
                <div class="mb-4">
                <label class="block text-gray-300 font-medium mb-2">Departamento</label>
                <input
                    v-model="form.department"
                    type="text"
                    class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Ventas"
                />
                </div>

                <!-- Posición -->
                <div class="mb-4">
                <label class="block text-gray-300 font-medium mb-2">Posición</label>
                <input
                    v-model="form.position"
                    type="text"
                    class="w-full bg-gray-700 text-white px-4 py-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Ej: Gerente de Ventas"
                />
                </div>

                <!-- Error -->
                <div v-if="error" class="mb-4 bg-red-900/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg">
                {{ error }}
                </div>

                <!-- Botones -->
                <div class="flex gap-4">
                <button
                    type="submit"
                    :disabled="loading"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 text-white py-3 rounded-lg font-medium transition"
                >
                    {{ loading ? 'Guardando...' : 'Guardar Cliente' }}
                </button>
                <button
                    type="button"
                    @click="router.push('/customers')"
                    class="px-6 bg-gray-700 hover:bg-gray-600 text-white py-3 rounded-lg transition"
                >
                    Cancelar
                </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const form = ref({
    name: '',
    last_name: '',
    department: '',
    position: '',
})

const loading = ref(false)
const error = ref('')

const handleSubmit = async () => {
    loading.value = true
    error.value = ''

    try {
        await api.customers.create(form.value)
        alert('Cliente creado exitosamente')
        router.push('/customers')
    } catch (err) {
        console.error('Error al crear cliente:', err)
        error.value = err.response?.data?.message || 'Error al crear el cliente. Por favor, intenta nuevamente.'
    } finally {
        loading.value = false
    }
}
</script>