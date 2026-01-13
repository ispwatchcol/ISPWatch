<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Ticket de Soporte</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Crea un nuevo ticket de soporte</p>
        </div>

        <!-- Formulario -->
        <div class="max-w-3xl bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 border border-gray-100 dark:border-gray-700">
            <form @submit.prevent="handleSubmit">
                <!-- Selección de Cliente -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Cliente <span class="text-red-500">*</span>
                    </label>
                    <p v-if="loadingCustomers" class="text-sm text-gray-500 dark:text-gray-400">Cargando clientes...</p>
                    <select
                        v-else
                        v-model="form.user_id"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors.user_id }"
                    >
                        <option value="">Selecciona un cliente</option>
                        <option
                            v-for="customer in customers"
                            :key="customer.user_id"
                            :value="customer.user_id"
                        >
                            {{ customer.fullname }}
                        </option>
                    </select>
                    <p v-if="errors.user_id" class="mt-1 text-sm text-red-500">{{ errors.user_id }}</p>
                </div>

                <!-- Asunto -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Asunto <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.subject"
                        type="text"
                        placeholder="Ej: Problema con conexión a internet"
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :class="{ 'border-red-500': errors.subject }"
                    />
                    <p v-if="errors.subject" class="mt-1 text-sm text-red-500">{{ errors.subject }}</p>
                </div>

                <!-- Botones -->
                <div class="flex gap-3">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span v-if="!submitting">Crear Ticket</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <div class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                            Creando...
                        </span>
                    </button>
                    <button
                        type="button"
                        @click="router.push('/support')"
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
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const form = ref({
    user_id: '', // Customer ID
    subject: ''
})

const customers = ref([])
const loadingCustomers = ref(false)

const errors = ref({})
const submitting = ref(false)

const validate = () => {
    errors.value = {}

    if (!form.value.user_id) {
        errors.value.user_id = 'El cliente es requerido'
    }

    if (!form.value.subject || form.value.subject.trim() === '') {
        errors.value.subject = 'El asunto es requerido'
    }

    return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
    if (!validate()) return

    try {
        submitting.value = true

        await api.support.create({
            user_id: form.value.user_id,
            subject: form.value.subject
        })

        alert('Ticket creado correctamente. ✅')
        router.push('/support')
    } catch (err) {
        console.error('Error al crear ticket:', err)
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors
        } else {
            alert('Error al crear el ticket. Por favor intenta de nuevo.')
        }
    } finally {
        submitting.value = false
    }
}

const loadCustomers = async () => {
    try {
        loadingCustomers.value = true
        const response = await api.customers.getAll()
        customers.value = response.data.map(c => ({
            ...c,
            fullname: `${c.name} ${c.last_name} - ${c.email}`
        }))
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        alert('Error al cargar la lista de clientes.')
    } finally {
        loadingCustomers.value = false
    }
}

onMounted(() => {
    loadCustomers()
})
</script>
