<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Clientes</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Gestión de perfiles de clientes</p>
        </div>
        <button
            @click="router.push('/customers/create')"
            class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition"
        >
            <v-icon name="pr-user-plus" class="w-5 h-5" />
            <span class="text-sm sm:text-base">Nuevo Cliente</span>
        </button>
        </div>

        <div class="mb-6 flex flex-col sm:flex-row gap-3 sm:gap-4 items-stretch sm:items-start">
            <div class="relative flex-1">
                <input
                v-model="searchQuery"
                type="text"
                placeholder="Buscar por nombre, usuario o rol..."
                class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-2.5 sm:py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm sm:text-base"
                />
                <v-icon name="io-search" class="absolute left-3 top-2.5 sm:top-3.5 w-5 h-5 text-gray-400" />
                <button
                v-if="searchQuery"
                @click="searchQuery = ''"
                class="absolute right-3 top-2 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                >
                <v-icon name="io-close-circle" class="w-6 h-6" />
                </button>
            </div>
            
            <button
                @click="provisionCustomer"
                class="bg-green-600 hover:bg-green-700 dark:bg-green-600 dark:hover:bg-green-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition whitespace-nowrap text-sm sm:text-base"
                title="Cargar cliente filtrado al Router Board"
            >
                <icon-lucide-server class="w-5 h-5" />
                <span>Cargar a RB</span>
            </button>
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

        <!-- Tabla de clientes (desktop) / Cards (mobile) -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
        <!-- Desktop table -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">ID</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nombre</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Apellido</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Email</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">IP</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Plan</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Sectorial</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Departamento</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Posición</th>
                <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Estado</th>
                <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="customer in filteredCustomers" :key="customer.user_id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.user_id }}</td>
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.last_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.email }}</td>
                <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ customer.ip_user || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.service_name || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.sectorial_name || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.department || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.position || '-' }}</td>
                <td class="px-6 py-4 text-center">
                    <span v-if="customer.status" class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">
                        Activo
                    </span>
                    <span v-else class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                        Suspendido
                    </span>
                </td>
                <td class="px-6 py-4 text-center">
                    <div class="flex justify-center gap-2 flex-wrap">
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
                        v-if="customer.status"
                        @click="suspendCustomer(customer.user_id)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-orange-50 text-orange-700 border border-orange-200
                            hover:bg-orange-100 hover:scale-[1.03] transition-all
                            dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800 dark:hover:bg-orange-800/50"
                    >
                        <icon-lucide-pause-circle class="w-4 h-4" />
                        Suspender
                    </button>
                    
                    <button
                        v-else
                        @click="activateCustomer(customer.user_id)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-green-50 text-green-700 border border-green-200
                            hover:bg-green-100 hover:scale-[1.03] transition-all
                            dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                    >
                        <icon-lucide-play-circle class="w-4 h-4" />
                        Activar
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
                <td colspan="11" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    {{ searchQuery ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                </td>
                </tr>
            </tbody>
            </table>
        </div>
        
        <!-- Mobile cards -->
        <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
            <div v-for="customer in filteredCustomers" :key="customer.user_id" class="p-4">
                <div class="space-y-3">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="font-semibold text-gray-800 dark:text-white">{{ customer.name }} {{ customer.last_name }}</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ customer.email }}</p>
                        </div>
                        <span v-if="customer.status" class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 whitespace-nowrap">
                            Activo
                        </span>
                        <span v-else class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 whitespace-nowrap">
                            Suspendido
                        </span>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">IP:</span>
                            <span class="ml-1 font-mono text-gray-800 dark:text-white">{{ customer.ip_user || '-' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Plan:</span>
                            <span class="ml-1 text-gray-800 dark:text-white">{{ customer.service_name || '-' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Sectorial:</span>
                            <span class="ml-1 text-gray-800 dark:text-white">{{ customer.sectorial_name || '-' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Depto:</span>
                            <span class="ml-1 text-gray-800 dark:text-white">{{ customer.department || '-' }}</span>
                        </div>
                    </div>
                    
                    <div class="flex flex-wrap gap-2 pt-2">
                        <button
                            @click="router.push(`/customers/${customer.user_id}/edit`)"
                            class="flex-1 min-w-[100px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-blue-50 text-blue-700 border border-blue-200
                                hover:bg-blue-100 transition-all
                                dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                        >
                            <icon-lucide-pencil class="w-4 h-4" />
                            <span>Editar</span>
                        </button>
                        
                        <button
                            v-if="customer.status"
                            @click="suspendCustomer(customer.user_id)"
                            class="flex-1 min-w-[100px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-orange-50 text-orange-700 border border-orange-200
                                hover:bg-orange-100 transition-all
                                dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800 dark:hover:bg-orange-800/50"
                        >
                            <icon-lucide-pause-circle class="w-4 h-4" />
                            <span>Suspender</span>
                        </button>
                        
                        <button
                            v-else
                            @click="activateCustomer(customer.user_id)"
                            class="flex-1 min-w-[100px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-green-50 text-green-700 border border-green-200
                                hover:bg-green-100 transition-all
                                dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                        >
                            <icon-lucide-play-circle class="w-4 h-4" />
                            <span>Activar</span>
                        </button>
                        
                        <button
                            @click="deleteCustomer(customer.user_id)"
                            class="px-3 py-2 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-red-50 text-red-700 border border-red-200
                                hover:bg-red-100 transition-all
                                dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                        >
                            <icon-lucide-trash-2 class="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>
            
            <div v-if="filteredCustomers.length === 0 && !loading" class="p-8 text-center text-gray-500 dark:text-gray-400">
                {{ searchQuery ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
            </div>
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
        const ip = customer.ip_user?.toLowerCase() || ''

        return (
        fullName.includes(query) ||
        email.includes(query) ||
        department.includes(query) ||
        position.includes(query) ||
        ip.includes(query)
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



const provisionCustomer = async () => {
    // Verificar si hay clientes filtrados
    if (filteredCustomers.value.length === 0) {
        alert('No hay clientes para provisionar. Por favor, ajusta tu búsqueda.')
        return
    }

    const count = filteredCustomers.value.length
    const customerIds = filteredCustomers.value.map(c => c.user_id)

    const confirmMsg = count === 1 
        ? `¿Estás seguro de cargar la configuración del cliente ${filteredCustomers.value[0].name} ${filteredCustomers.value[0].last_name} al Router?`
        : `¿Estás seguro de provisionar ${count} clientes al Router Board?`

    if (!confirm(confirmMsg)) {
        return
    }

    try {
        loading.value = true
        const response = await api.customers.bulkProvision(customerIds)
        
        // Mostrar resumen
        let message = `${response.data.summary}\n\n`
        
        // Mostrar detalles si hay fallos
        if (response.data.fail_count > 0) {
            message += 'Detalles:\n'
            response.data.results.forEach(r => {
                if (!r.success) {
                    message += `❌ ${r.customer_name || 'ID:' + r.customer_id}: ${r.message}\n`
                }
            })
        }
        
        if (response.data.success_count > 0) {
            message += `\n✅ ${response.data.success_count} cliente(s) provisionado(s) exitosamente.`
        }
        
        alert(message)
    } catch (err) {
        console.error('Error al provisionar clientes:', err)
        const msg = err.response?.data?.message || 'Error al conectar con el router.'
        alert(`Error: ${msg}`)
    } finally {
        loading.value = false
    }
}

const suspendCustomer = async (id) => {
    if (!confirm('¿Estás seguro de suspender este cliente? Se bloqueará su acceso al servicio.')) return

    try {
        loading.value = true
        const response = await api.customers.suspend(id)
        alert(response.data.message)
        loadCustomers()
    } catch (err) {
        console.error('Error al suspender cliente:', err)
        console.error('Response:', err.response)
        // Si el error es 400, es porque ya está suspendido
        if (err.response?.status === 400) {
            alert(err.response.data.message || 'El cliente ya está suspendido.')
            loadCustomers() // Actualizar lista para reflejar estado correcto
        } else {
            const errorMsg = err.response?.data?.message 
                || err.response?.data?.error 
                || `Error ${err.response?.status || 'de red'}: ${err.message}`
            alert('Error al suspender el cliente:\n' + errorMsg)
        }
    } finally {
        loading.value = false
    }
}

const activateCustomer = async (id) => {
    if (!confirm('¿Estás seguro de activar este cliente? Se restaurará su acceso al servicio.')) return

    try {
        loading.value = true
        const response = await api.customers.activate(id)
        alert(response.data.message)
        loadCustomers()
    } catch (err) {
        console.error('Error al activar cliente:', err)
        // Si el error es 400, es porque ya está activo
        if (err.response?.status === 400) {
            alert(err.response.data.message || 'El cliente ya está activo.')
            loadCustomers() // Actualizar lista para reflejar estado correcto
        } else {
            alert('Error al activar el cliente: ' + (err.response?.data?.message || 'Error de conexión'))
        }
    } finally {
        loading.value = false
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