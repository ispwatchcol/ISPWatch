<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
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

        <!-- Filtros y Acciones -->
        <div class="flex flex-wrap items-center justify-between mb-6 gap-4">
          <!-- Lado Izquierdo: Buscador y Limpiar -->
          <div class="flex items-center gap-2 w-full sm:w-auto">
            <div class="relative w-full sm:w-80">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, IP o usuario..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 px-4 py-2 pl-10 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 shadow-sm"
                />
                <v-icon name="io-search" class="absolute left-3 top-2.5 w-5 h-5 text-gray-400" />
            </div>
            
            <button
              @click="clearSearch"
              class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all font-medium shadow-sm h-[42px]"
            >
              Limpiar
            </button>
          </div>

          <!-- Lado Derecho: Botones Exportar -->
          <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
            <!-- Export CSV -->
            <button
              @click="exportToCSV"
              class="text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50 shadow-sm h-[42px]"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="w-4 h-4" />
              CSV
            </button>

             <!-- Export Excel -->
            <button
              @click="exportToExcel"
              class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 transition-all flex items-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50 shadow-sm h-[42px]"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="w-4 h-4" />
              Excel
            </button>
          </div>
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
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Router</th>
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
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                    <span v-if="getRouterName(sectorial.zona_id)" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-md text-xs font-medium">
                        <v-icon name="bi-router" class="w-3 h-3" />
                        {{ getRouterName(sectorial.zona_id) }}
                    </span>
                    <span v-else class="text-gray-400">-</span>
                </td>
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
import { supabase } from '@/supabase.js'
import api from '../services/api'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'

const router = useRouter()

const sectorials = ref([])
const routers = ref([])
const loading = ref(true)
const error = ref('')
const searchQuery = ref('')
const toast = ref(null)

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

// Función para obtener el nombre del router por ID
const getRouterName = (zonaId) => {
    if (!zonaId) return null
    const router = routers.value.find(r => r.id === zonaId)
    return router ? router.name : null
}

// Cargar routers del tenant actual
const loadRouters = async () => {
    try {
        const userData = 
            JSON.parse(localStorage.getItem("userData")) ??
            JSON.parse(sessionStorage.getItem("userData"))

        if (!userData?.tenant_id) {
            console.error("No se encontró tenant_id")
            return
        }

        const { data, error: fetchError } = await supabase
            .from("router")
            .select("id, name, ip")
            .eq("tenant_id", userData.tenant_id)

        if (fetchError) {
            console.error("Error al cargar routers:", fetchError.message)
            return
        }

        routers.value = data || []
    } catch (err) {
        console.error('Error al cargar routers:', err)
    }
}

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
        toast.value?.success(
            'Sectorial eliminada',
            'La sectorial ha sido eliminada correctamente'
        )
        loadSectorials()
    } catch (err) {
        console.error('Error al eliminar sectorial:', err)
        toast.value?.error(
            'Error al eliminar',
            'No se pudo eliminar la sectorial. Intenta de nuevo.'
        )
    }
}

const clearSearch = () => {
    searchQuery.value = ''
}

// Export Helper
const generateCSV = (withBOM = false) => {
  if (filteredSectorials.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return null
  }

  // Headers
  const headers = ['Nombre', 'Tipo', 'Usuario RB', 'Zona ID', 'Frecuencia', 'Nodo Torre']
  
  // Rows
  const rows = filteredSectorials.value.map(s => [
    `"${s.name || ''}"`,
    `"${s.type || ''}"`,
    `"${s.user_rb || ''}"`,
    `"${s.zona_id || ''}"`,
    `"${s.frequency || ''}"`,
    `"${s.node_tower || ''}"`
  ])

  // Combine headers and rows
  const csvContent = [
    headers.join(','), 
    ...rows.map(row => row.join(','))
  ].join('\n')

  return withBOM ? '\uFEFF' + csvContent : csvContent
}

const downloadFile = (content, filename, mimeType) => {
  if (!content) return
  
  const blob = new Blob([content], { type: mimeType })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.setAttribute('href', url)
  link.setAttribute('download', filename)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// Export to CSV
const exportToCSV = () => {
  const content = generateCSV(false)
  const date = new Date().toISOString().split('T')[0]
  downloadFile(content, `sectorials_list_${date}.csv`, 'text/csv;charset=utf-8;')
}

// Export to Excel (XLSX)
const exportToExcel = () => {
  if (filteredSectorials.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return
  }

  // Prepare data for Excel
  const data = filteredSectorials.value.map(s => ({
    'Nombre': s.name || '',
    'Tipo': s.type || '',
    'Usuario RB': s.user_rb || '',
    'Zona ID': s.zona_id || '',
    'Frecuencia': s.frequency || '',
    'Nodo Torre': s.node_tower || ''
  }))

  // Create worksheet from data
  const worksheet = XLSX.utils.json_to_sheet(data)
  
  // Set column widths for better readability
  worksheet['!cols'] = [
    { wch: 25 }, // Nombre
    { wch: 15 }, // Tipo
    { wch: 15 }, // Usuario
    { wch: 10 }, // Zona
    { wch: 15 }, // Frecuencia
    { wch: 20 }  // Nodo Torre
  ]

  // Create workbook and add worksheet
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Sectoriales')

  // Generate filename with current date
  const date = new Date().toISOString().split('T')[0]
  const filename = `sectorials_excel_${date}.xlsx`

  // Write and download file
  XLSX.writeFile(workbook, filename)
}

onMounted(() => {
    loadRouters()
    loadSectorials()
})
</script>