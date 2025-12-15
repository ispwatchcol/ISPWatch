<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Clientes</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Gestión de perfiles de clientes</p>
        </div>
        <button
            @click="router.push('/customers/create')"
            class="bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white px-6 py-3 rounded-lg flex items-center gap-2 transition"
        >
            <v-icon name="pr-user-plus" class="w-5 h-5" />
            Nuevo Cliente
        </button>
        </div>

        <!-- Buscador -->
        <div class="mb-6">
        <div class="relative max-w-md">
            <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar por nombre, usuario o rol..."
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
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando clientes...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
        {{ error }}
        </div>

        <!-- Tabla de clientes -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">ID</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nombre</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Apellido</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Email</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Departamento</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Posición</th>
                <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="customer in filteredCustomers" :key="customer.user_id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.user_id }}</td>
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.last_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.email }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.department || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.position || '-' }}</td>
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

                <tr v-if="filteredCustomers.length === 0 && !loading">
                <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    {{ searchQuery ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                </td>
                </tr>
            </tbody>
            </table>
        </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../services/api'

const router = useRouter();

const customers = ref([]);
const loading = ref(true);
const error = ref('');
const searchQuery = ref('');

// computed property para filtrar clientes
const filteredCustomers = computed(() => {
    if (!searchQuery.value) {
        return customers.value;
    }

    const query = searchQuery.value.toLowerCase().trim()

    return customers.value.filter(customer => {
        const fullName = `${customer.name} ${customer.last_name}`.toLowerCase()
        const email = customer.email?.toLowerCase() || ''
        const department = customer.department?.toLowerCase() || ''
        const position = customer.position?.toLowerCase() || ''

        return (
        fullName.includes(query) ||
        email.includes(query) ||
        department.includes(query) ||
        position.includes(query)
        )
    })
})

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