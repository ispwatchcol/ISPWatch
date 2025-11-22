<template>
    <div class="min-h-screen bg-gray-900 p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Clientes</h1>
            <p class="text-gray-400 mt-1">Gestión de perfiles de clientes</p>
        </div>
        <button
        @click="router.push('/customers/create')"
        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg flex items-center gap-2 transition"
        >
        <icon-mdi-plus class="w-5 h-5" />
        Nuevo Cliente
        </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-400 mt-4">Cargando clientes...</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-900/20 border border-red-500 text-red-400 px-4 py-3 rounded-lg">
        {{ error }}
    </div>

    <!-- Tabla de clientes -->
    <div v-else class="bg-gray-800 rounded-lg shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-700">
                    <tr>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase">Nombre</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase">Apellido</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase">Departamento</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-300 uppercase">Posición</th>
                    <th class="px-6 py-4 text-center text-xs font-medium text-gray-300 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <tr v-for="customer in customers" :key="customer.user_id" class="hover:bg-gray-750 transition">
                    <td class="px-6 py-4 text-sm text-white">{{ customer.name }}</td>
                    <td class="px-6 py-4 text-sm text-white">{{ customer.last_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-300">{{ customer.department || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-300">{{ customer.position || '-' }}</td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex justify-center gap-2">
                        <button
                            @click="router.push(`/customers/${customer.user_id}/edit`)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-blue-50 text-blue-700 border border-blue-200
                                hover:bg-blue-100 hover:scale-[1.03] transition-all
                                dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                        >
                            <icon-lucide-pencil class="w-4 h-4" />
                            Editar
                        </button>
                        <button
                            @click="deleteCustomer(customer.user_id)"
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

                    <tr v-if="customers.length === 0">
                    <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                        No hay clientes registrados
                    </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../services/api'

const router = useRouter();

const customers = ref([]);
const loading = ref(true);
const error = ref('');

const loadCustomers = async () => {
    try {
        loading.value = true;
        const response = await api.customers.getAll()
        customers.value = response.data
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        error.value = 'Error al cargar los clientes.'
    } finally {
        loading.value = false;
    }
}

const deleteCustomer = async (id) => {
    if (!confirm('¿Estás seguro de eliminar este cliente?')) return

    try {
        await api.customers.delete(id)
        alert('Cliente eliminado correctamente.')
        loadCustomers()
    } catch (err) {
        console.error('Error al eliminar cliente:', err)
        alert('Error al eliminar el cliente.')
    }
}

onMounted(() => {
    loadCustomers()
})
</script>