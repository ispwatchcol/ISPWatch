<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />

    <!-- Contenido principal -->
    <main class="flex-1 p-6 overflow-y-auto">
      <!-- Encabezado -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="bi-people" class="text-blue-600 w-7 h-7" />
            Personal del Sistema
          </h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
            Gestión del personal y control de accesos.
          </p>
        </div>
        <!-- Botón Crear nuevo Staff -->
        <button
          @click="$router.push('/staff/create')"
          class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center gap-2 transition-all"
        >
          <icon-lucide-user-plus class="w-4 h-4" />
          Nuevo Staff
        </button>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-6">
        <!-- Filtros -->
        <!-- Filtros y Acciones -->
        <div class="flex flex-wrap items-center justify-between mb-4 gap-4">
          <!-- Lado Izquierdo: Búsqueda y Limpiar -->
          <div class="flex items-center gap-2 w-full sm:w-auto">
            <input
              v-model="search"
              type="text"
              placeholder="Buscar por nombre, usuario o rol"
              class="border border-gray-300 dark:border-gray-700 rounded-xl px-4 py-2 w-full sm:w-80 focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white"
            />
            <button
              @click="clearSearch"
              class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all whitespace-nowrap"
            >
              Limpiar
            </button>
          </div>

          <!-- Lado Derecho: Exportar -->
          <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
             <!-- Export CSV -->
            <button
              @click="exportToCSV"
              class="text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="w-4 h-4" />
              CSV
            </button>

             <!-- Export Excel -->
            <button
              @click="exportToExcel"
              class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 transition-all flex items-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="w-4 h-4" />
              Excel
            </button>
          </div>
        </div>



        <!-- Tabla -->
        <!-- Loading state -->
        <div v-if="loading" class="text-center py-12">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
          <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando personal...</p>
        </div>

        <!-- Tabla -->
        <div v-if="!loading" class="overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm uppercase tracking-wide">
                <th @click="sortBy('user_name')" class="py-3 px-4 text-left cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors select-none">
                  <div class="flex items-center gap-1">
                    Nombre
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'user_name'" class="w-3 h-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'user_name' && sortDir === 'asc'" class="w-3 h-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'user_name' && sortDir === 'desc'" class="w-3 h-3 text-blue-600" />
                  </div>
                </th>
                <th @click="sortBy('email_tenant')" class="py-3 px-4 text-left cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors select-none">
                  <div class="flex items-center gap-1">
                    Usuario
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'email_tenant'" class="w-3 h-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'email_tenant' && sortDir === 'asc'" class="w-3 h-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'email_tenant' && sortDir === 'desc'" class="w-3 h-3 text-blue-600" />
                  </div>
                </th>
                <th @click="sortBy('role_name')" class="py-3 px-4 text-left cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors select-none">
                  <div class="flex items-center gap-1">
                    Rol
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'role_name'" class="w-3 h-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'role_name' && sortDir === 'asc'" class="w-3 h-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'role_name' && sortDir === 'desc'" class="w-3 h-3 text-blue-600" />
                  </div>
                </th>
                <th @click="sortBy('create_at')" class="py-3 px-4 text-left cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors select-none">
                  <div class="flex items-center gap-1">
                    Creado
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'create_at'" class="w-3 h-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'create_at' && sortDir === 'asc'" class="w-3 h-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'create_at' && sortDir === 'desc'" class="w-3 h-3 text-blue-600" />
                  </div>
                </th>
                <th @click="sortBy('last_access')" class="py-3 px-4 text-left cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors select-none">
                  <div class="flex items-center gap-1">
                    Último Acceso
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'last_access'" class="w-3 h-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'last_access' && sortDir === 'asc'" class="w-3 h-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'last_access' && sortDir === 'desc'" class="w-3 h-3 text-blue-600" />
                  </div>
                </th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <!-- Data Rows -->
              <tr
                v-for="member in sortedStaff"
                :key="member.id"
                class="border-b border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-700/40 transition-all"
              >
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">{{ member.user_name }} {{ member.user_lastname }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ member.email_tenant }}</td>
                <td>
                  <span
                    class="inline-block px-3 py-1 text-xs font-semibold rounded-full"
                    :class="getRoleColor(member.role_name)"
                  >
                    {{ member.role_name }}
                  </span>
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">
                  {{ formatDate(member.create_at) }}
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">
                  {{ member.last_access ? formatDate(member.last_access) : '—' }}
                </td>
                <td class="py-3 px-4 flex gap-2">
                  <!-- Botón Editar -->
                  <button
                    @click="$router.push(`/staff/${member.id}/edit`)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-blue-50 text-blue-700 border border-blue-200
                          hover:bg-blue-100 hover:scale-[1.03] transition-all
                          dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                  >
                    <icon-lucide-pencil class="w-4 h-4" />
                    Editar
                  </button>

                  <!-- Botón Eliminar -->
                  <button
                    @click="deleteUser(member.id)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-red-50 text-red-700 border border-red-200
                          hover:bg-red-100 hover:scale-[1.03] transition-all
                          dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                  >
                    <icon-lucide-trash-2 class="w-4 h-4" />
                    Eliminar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <!-- empty state -->
          <div v-if="sortedStaff.length === 0" class="text-center py-8">
            <p class="text-gray-500 dark:text-gray-400">No se encontraron resultados</p>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../services/api.js'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'

//  reactive states
const search = ref('')
const staff = ref([])
const tenantId = ref(null)
const loading = ref(true)
const sortCol = ref('create_at')
const toast = ref(null)
const sortDir = ref('desc')

// load staff data from API
const loadStaff = async () => {
  loading.value = true
  try {
    // get user sesion from localStorage or sessionStorage
    const sessionData =
      JSON.parse(localStorage.getItem("userData")) ||
      JSON.parse(sessionStorage.getItem("userData"))

    tenantId.value = sessionData?.tenant_id

    if (!tenantId.value) {
      console.error("❌ No se encontró tenant_id en la sesión del usuario.")
      toast.value?.error(
        'Sesión inválida',
        'No se encontró información del tenant. Por favor inicia sesión nuevamente.'
      )
      return
    }

    // call API to get staff data
    const response = await api.staff.getAll({ tenant_id: tenantId.value })

    if (response.data.success) {
      staff.value = response.data.data
    } else {
      console.error("Error en respuesta: ", response.data)
      toast.value?.error(
        'Error de carga',
        'Error al cargar el personal del sistema'
      )
    }
  } catch (error) {
    console.error("⚠️ Error al cargar staff:", error.response?.data || error.message)
    toast.value?.error(
      'Error de conexión',
      'Error al cargar el personal. Por favor intenta nuevamente.'
    )
  } finally {
    loading.value = false
  }
}

onMounted(loadStaff)

// search filter
const filteredStaff = computed(() =>
  staff.value
    .filter(member => member.role_name !== 'Cliente') // Exclude Clients
    .filter(member =>
      [member.user_name, member.user_lastname, member.email_tenant, member.role_name]
        .filter(Boolean)
        .some(f => f.toLowerCase().includes(search.value.toLowerCase()))
    )
)

// Sorted staff
const sortedStaff = computed(() => {
  return [...filteredStaff.value].sort((a, b) => {
    let valA = a[sortCol.value]
    let valB = b[sortCol.value]

    // Handle null/undefined values
    if (valA === null || valA === undefined) valA = ''
    if (valB === null || valB === undefined) valB = ''

    // Specific logic for name (combine first and last name if needed, but here simple prop)
    if (sortCol.value === 'user_name') {
       valA = (a.user_name || '') + ' ' + (a.user_lastname || '')
       valB = (b.user_name || '') + ' ' + (b.user_lastname || '')
    }

    if (valA < valB) return sortDir.value === 'asc' ? -1 : 1
    if (valA > valB) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
})

// sort toggle function
const sortBy = (col) => {
  if (sortCol.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortCol.value = col
    sortDir.value = 'asc'
  }
}

// clear search input
const clearSearch = () => (search.value = "")

// dynamic role color classes
const getRoleColor = (role) => {
  // Normalize role string to lower case for comparison if needed, 
  // but usually exact match is better for stability if data is consistent.
  // Using partial match or logical groups.
  const r = role ? role.toLowerCase() : ''

  if (r.includes('admin')) return "bg-blue-100 text-blue-700 border border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
  if (r.includes('finanzas') || r.includes('contabil')) return "bg-cyan-100 text-cyan-700 border border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800"
  if (r.includes('tec') || r.includes('téc')) return "bg-purple-100 text-purple-700 border border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800"
  if (r.includes('staff')) return "bg-indigo-100 text-indigo-700 border border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:border-indigo-800"
  if (r.includes('almacen')) return "bg-orange-100 text-orange-700 border border-orange-200 dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800"
  if (r.includes('cliente')) return "bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800"

  // default
  return "bg-gray-100 text-gray-700 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700"
}

// disable user (soft delete)
const deleteUser = async (id) => {
  if (!confirm("¿Seguro que deseas desactivar este usuario?")) return
  
  try {
    const response = await api.staff.delete(id)

    if (response.data.success) {
      toast.value?.success(
        'Usuario desactivado',
        'El usuario ha sido desactivado correctamente'
      )
      await loadStaff() // reload staff list after deletion
    }
  } catch (erorr) {
      console.error("❌ Error al desactivar usuario:", error.response?.data || error.message)
      toast.value?.error(
        'Error al desactivar',
        'Error al desactivar el usuario. Por favor intenta nuevamente.'
      )
  }
}

// readable date format
const formatDate = (dateStr) => {
  if (!dateStr) return "—"
  const date = new Date(dateStr)
  return date.toLocaleString("es-CO", {
    dateStyle: "short",
    timeStyle: "short",
  })
}

// Export Helper
const generateCSV = (withBOM = false) => {
  if (sortedStaff.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return null
  }

  // Headers
  const headers = ['Nombre', 'Usuario', 'Rol', 'Creado', 'Último Acceso']
  
  // Rows
  const rows = sortedStaff.value.map(member => [
    `"${(member.user_name || '')} ${(member.user_lastname || '')}"`,
    `"${member.email_tenant || ''}"`,
    `"${member.role_name || ''}"`,
    `"${formatDate(member.create_at)}"`,
    `"${member.last_access ? formatDate(member.last_access) : '—'}"`
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
  downloadFile(content, `staff_list_${date}.csv`, 'text/csv;charset=utf-8;')
}

// Export to Excel (CSV compatible)
const exportToExcel = () => {
  if (sortedStaff.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return
  }

  // Prepare data for Excel
  const data = sortedStaff.value.map(member => ({
    'Nombre': `${member.user_name || ''} ${member.user_lastname || ''}`.trim(),
    'Usuario': member.email_tenant || '',
    'Rol': member.role_name || '',
    'Creado': formatDate(member.create_at),
    'Último Acceso': member.last_access ? formatDate(member.last_access) : '—'
  }))

  // Create worksheet from data
  const worksheet = XLSX.utils.json_to_sheet(data)
  
  // Set column widths for better readability
  worksheet['!cols'] = [
    { wch: 25 }, // Nombre
    { wch: 30 }, // Usuario
    { wch: 20 }, // Rol
    { wch: 20 }, // Creado
    { wch: 20 }  // Último Acceso
  ]

  // Create workbook and add worksheet
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Personal')

  // Generate filename with current date
  const date = new Date().toISOString().split('T')[0]
  const filename = `staff_excel_${date}.xlsx`

  // Write and download file
  XLSX.writeFile(workbook, filename)
}
</script>
