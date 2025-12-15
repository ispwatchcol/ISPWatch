<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Sectoriales del Sistema</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Gestión de sectoriales y configuración por zonas</p>
        </div>
        <button
            @click="router.push('/sectorials/create')"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center gap-2 transition-all"
        >
            <icon-lucide-plus class="w-4 h-4" />
            Agregar Sectorial
        </button>
        </div>

        <!-- Buscador -->
        <div class="mb-6">
        <div class="relative max-w-md">
            <input
            v-model="searchQuery"
            type="text"
            placeholder="Buscar por nombre, IP o usuario..."
            class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400"
            />
            <v-icon name="io-search" class="absolute left-3 top-3.5 w-5 h-5 text-gray-400" />
            <button
            v-if="searchQuery"
            @click="clearSearch"
            class="absolute right-3 top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
            >
            ✕
            </button>
        </div>
        <button
            v-if="searchQuery"
            @click="clearSearch"
            class="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
        >
            Limpiar
        </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando sectoriales...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
        {{ error }}
        </div>

        <!-- Tabla de sectoriales -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nombre</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Tipo</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Usuario RB</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Zona ID</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Frecuencia</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nodo Torre</th>
                <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="sectorial in filteredSectorials" :key="sectorial.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white font-medium">{{ sectorial.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.type || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.user_rb || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.zona_id || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.frequency || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.node_tower || '-' }}</td>
                <td class="px-6 py-4">
                    <div class="flex justify-center gap-2">
                    <button
                        @click="router.push(`/sectorials/${sectorial.id}/edit`)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-blue-50 text-blue-700 border border-blue-200
                            hover:bg-blue-100 hover:scale-[1.03] transition-all
                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                    >
                    <icon-lucide-pencil class="w-4 h-4" />
                        Editar
                    </button>
                    <button
                        @click="router.push()"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-cyan-50 text-cyan-700 border border-cyan-200
                            hover:bg-cyan-100 hover:scale-[1.03] transition-all
                            dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800 dark:hover:bg-cyan-800/50"
                    >
                        <icon-lucide-bar-chart-3 class="w-4 h-4" />
                        Detalles
                    </button>
                    <button
                        @click="deleteSectorial(sectorial.id)"
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

                <tr v-if="filteredSectorials.length === 0 && !loading">
                <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    {{ searchQuery ? 'No se encontraron resultados' : 'No hay sectoriales registradas' }}
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

const router = useRouter()

const sectorials = ref([])
const loading = ref(true)
const error = ref('')
const searchQuery = ref('')

// Computed para filtrar sectoriales
const filteredSectorials = computed(() => {
    if (!searchQuery.value) {
        return sectorials.value
    }

    const query = searchQuery.value.toLowerCase().trim()
    
    return sectorials.value.filter(sectorial => {
        const name = sectorial.name?.toLowerCase() || ''
        const type = sectorial.type?.toLowerCase() || ''
        const userRb = sectorial.user_rb?.toLowerCase() || ''
        const nodeTower = sectorial.node_tower?.toLowerCase() || ''

        return (
        name.includes(query) ||
        type.includes(query) ||
        userRb.includes(query) ||
        nodeTower.includes(query)
        )
    })
})

const loadSectorials = async () => {
    try {
        loading.value = true
        const response = await api.sectorials.getAll()
        sectorials.value = response.data
    } catch (err) {
        console.error('Error al cargar sectoriales:', err)
        error.value = 'Error al cargar las sectoriales'
    } finally {
        loading.value = false
    }
}

const deleteSectorial = async (id) => {
    if (!confirm('¿Estás seguro de eliminar esta sectorial?')) return

    try {
        await api.sectorials.delete(id)
        alert('Sectorial eliminada correctamente')
        loadSectorials()
    } catch (err) {
        console.error('Error al eliminar sectorial:', err)
        alert('Error al eliminar la sectorial')
    }
}

const clearSearch = () => {
    searchQuery.value = ''
}

onMounted(() => {
    loadSectorials()
})
</script>