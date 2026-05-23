<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <NotificationToast ref="toast" />

    <main class="flex-1 overflow-y-auto p-6">
      <div class="mb-8 flex items-center justify-between">
        <div>
          <h1 class="flex items-center gap-2 text-3xl font-semibold text-gray-800 dark:text-gray-100">
            <v-icon name="bi-people" class="h-7 w-7 text-blue-600" />
            Personal del Sistema
          </h1>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Gestion del personal y control de accesos.
          </p>
        </div>

        <button
          v-if="can('view_staff')"
          @click="$router.push('/staff/create')"
          class="flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-white shadow-md transition-all hover:bg-blue-700"
        >
          <icon-lucide-user-plus class="h-4 w-4" />
          Nuevo Staff
        </button>
      </div>

      <div class="rounded-2xl bg-white p-6 shadow dark:bg-gray-800">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
          <div class="flex w-full items-center gap-2 sm:w-auto">
            <input
              v-model="search"
              type="text"
              placeholder="Buscar por nombre, usuario o rol"
              class="w-full rounded-xl border border-gray-300 px-4 py-2 outline-none focus:ring-2 focus:ring-blue-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white sm:w-80"
            />
            <button
              @click="clearSearch"
              class="whitespace-nowrap rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600 transition-all hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
            >
              Limpiar
            </button>
          </div>

          <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
            <button
              @click="exportToCSV"
              class="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700 transition-all hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="h-4 w-4" />
              CSV
            </button>

            <button
              @click="exportToExcel"
              class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700 transition-all hover:bg-green-100 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300 dark:hover:bg-green-800/50"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="h-4 w-4" />
              Excel
            </button>
          </div>
        </div>

        <div v-if="loading" class="py-12 text-center">
          <div class="inline-block h-12 w-12 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
          <p class="mt-4 text-gray-500 dark:text-gray-400">Cargando personal...</p>
        </div>

        <div v-if="!loading" class="overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 text-sm uppercase tracking-wide text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                <th
                  @click="sortBy('name')"
                  class="cursor-pointer select-none py-3 px-4 text-left transition-colors hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                  <div class="flex items-center gap-1">
                    Nombre
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'name'" class="h-3 w-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'name' && sortDir === 'asc'" class="h-3 w-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'name' && sortDir === 'desc'" class="h-3 w-3 text-blue-600" />
                  </div>
                </th>
                <th
                  @click="sortBy('email_tenant')"
                  class="cursor-pointer select-none py-3 px-4 text-left transition-colors hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                  <div class="flex items-center gap-1">
                    Usuario
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'email_tenant'" class="h-3 w-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'email_tenant' && sortDir === 'asc'" class="h-3 w-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'email_tenant' && sortDir === 'desc'" class="h-3 w-3 text-blue-600" />
                  </div>
                </th>
                <th
                  @click="sortBy('role_name')"
                  class="cursor-pointer select-none py-3 px-4 text-left transition-colors hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                  <div class="flex items-center gap-1">
                    Rol
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'role_name'" class="h-3 w-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'role_name' && sortDir === 'asc'" class="h-3 w-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'role_name' && sortDir === 'desc'" class="h-3 w-3 text-blue-600" />
                  </div>
                </th>
                <th
                  @click="sortBy('create_at')"
                  class="cursor-pointer select-none py-3 px-4 text-left transition-colors hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                  <div class="flex items-center gap-1">
                    Creado
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'create_at'" class="h-3 w-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'create_at' && sortDir === 'asc'" class="h-3 w-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'create_at' && sortDir === 'desc'" class="h-3 w-3 text-blue-600" />
                  </div>
                </th>
                <th
                  @click="sortBy('last_access')"
                  class="cursor-pointer select-none py-3 px-4 text-left transition-colors hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                  <div class="flex items-center gap-1">
                    Ultimo Acceso
                    <icon-lucide-arrow-up-down v-if="sortCol !== 'last_access'" class="h-3 w-3 opacity-50" />
                    <icon-lucide-arrow-up v-if="sortCol === 'last_access' && sortDir === 'asc'" class="h-3 w-3 text-blue-600" />
                    <icon-lucide-arrow-down v-if="sortCol === 'last_access' && sortDir === 'desc'" class="h-3 w-3 text-blue-600" />
                  </div>
                </th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="member in sortedStaff"
                :key="member.id"
                class="border-b border-gray-200 transition-all hover:bg-blue-50 dark:border-gray-700 dark:hover:bg-gray-700/40"
              >
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">
                  {{ buildMemberName(member) }}
                </td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">
                  {{ member.email_tenant }}
                </td>
                <td>
                  <span
                    class="inline-block rounded-full px-3 py-1 text-xs font-semibold"
                    :class="getRoleColor(member.role_name)"
                  >
                    {{ member.role_name }}
                  </span>
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">
                  {{ formatDate(member.create_at) }}
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">
                  {{ member.last_access ? formatDate(member.last_access) : '-' }}
                </td>
                <td class="flex gap-2 py-3 px-4">
                  <button
                    v-if="can('view_staff')"
                    @click="$router.push(`/staff/${member.id}/edit`)"
                    class="flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition-all hover:scale-[1.03] hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50"
                  >
                    <icon-lucide-pencil class="h-4 w-4" />
                    Editar
                  </button>

                  <button
                    v-if="can('view_staff')"
                    @click="openDeleteModal(member)"
                    class="flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition-all hover:scale-[1.03] hover:bg-red-100 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-800/50"
                  >
                    <icon-lucide-trash-2 class="h-4 w-4" />
                    Eliminar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>

          <div v-if="sortedStaff.length === 0" class="py-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">No se encontraron resultados</p>
          </div>
        </div>
      </div>
    </main>

    <Teleport to="body">
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
      >
        <div
          class="absolute inset-0 bg-black/50 backdrop-blur-sm"
          @click="closeDeleteModal"
        />

        <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
          <div class="flex items-start justify-between gap-4">
            <div>
              <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                Eliminar staff
              </h2>
              <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Vas a desactivar a <strong>{{ memberToDeleteName }}</strong>. Para confirmar, escribe
                <span class="rounded bg-red-100 px-2 py-1 font-mono text-red-700 dark:bg-red-900/30 dark:text-red-300">
                  {{ DELETE_STAFF_CONFIRMATION }}
                </span>
              </p>
            </div>

            <button
              type="button"
              class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-gray-700 dark:hover:text-gray-200"
              :disabled="deletingUser"
              @click="closeDeleteModal"
            >
              <v-icon name="io-close" class="h-5 w-5" />
            </button>
          </div>

          <div class="mt-5">
            <input
              v-model="deleteConfirmationText"
              type="text"
              :placeholder="DELETE_STAFF_CONFIRMATION"
              class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-red-400 focus:ring-2 focus:ring-red-200 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:focus:border-red-500 dark:focus:ring-red-900/40"
              :disabled="deletingUser"
              @keyup.enter="confirmDeleteUser"
            />
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
              La confirmacion debe coincidir exactamente.
            </p>
          </div>

          <div class="mt-6 flex justify-end gap-3">
            <button
              type="button"
              class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700"
              :disabled="deletingUser"
              @click="closeDeleteModal"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="rounded-xl bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="deletingUser || !isDeleteConfirmationValid"
              @click="confirmDeleteUser"
            >
              {{ deletingUser ? 'Eliminando...' : 'Eliminar staff' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'
import api from '../services/api.js'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const search = ref('')
const staff = ref([])
const tenantId = ref(null)
const loading = ref(true)
const sortCol = ref('create_at')
const sortDir = ref('desc')
const toast = ref(null)
const showDeleteModal = ref(false)
const memberToDelete = ref(null)
const deleteConfirmationText = ref('')
const deletingUser = ref(false)

const DELETE_STAFF_CONFIRMATION = 'eliminar_staff'

const buildMemberName = member =>
  member?.name || `${member?.user_name || ''} ${member?.user_lastname || ''}`.trim()

const loadStaff = async () => {
  loading.value = true

  try {
    const sessionData =
      JSON.parse(localStorage.getItem('userData')) ||
      JSON.parse(sessionStorage.getItem('userData'))

    tenantId.value = sessionData?.tenant_id

    if (!tenantId.value) {
      console.error('No se encontro tenant_id en la sesion del usuario.')
      toast.value?.error(
        'Sesion invalida',
        'No se encontro informacion del tenant. Por favor inicia sesion nuevamente.'
      )
      return
    }

    const response = await api.staff.getAll({ tenant_id: tenantId.value })

    if (response.data.success) {
      staff.value = response.data.data
    } else {
      console.error('Error en respuesta:', response.data)
      toast.value?.error(
        'Error de carga',
        'Error al cargar el personal del sistema.'
      )
    }
  } catch (error) {
    console.error('Error al cargar staff:', error.response?.data || error.message)
    toast.value?.error(
      'Error de conexion',
      'Error al cargar el personal. Por favor intenta nuevamente.'
    )
  } finally {
    loading.value = false
  }
}

onMounted(loadStaff)

const filteredStaff = computed(() =>
  staff.value
    .filter(member => member.role_name !== 'Cliente')
    .filter(member =>
      [
        member.name,
        member.user_name,
        member.user_lastname,
        member.email_tenant,
        member.role_name,
      ]
        .filter(Boolean)
        .some(field => field.toLowerCase().includes(search.value.toLowerCase()))
    )
)

const sortedStaff = computed(() => {
  return [...filteredStaff.value].sort((a, b) => {
    let valA = a[sortCol.value]
    let valB = b[sortCol.value]

    if (valA === null || valA === undefined) valA = ''
    if (valB === null || valB === undefined) valB = ''

    if (sortCol.value === 'name') {
      valA = buildMemberName(a)
      valB = buildMemberName(b)
    }

    if (valA < valB) return sortDir.value === 'asc' ? -1 : 1
    if (valA > valB) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
})

const memberToDeleteName = computed(() => {
  if (!memberToDelete.value) return ''
  return buildMemberName(memberToDelete.value)
})

const isDeleteConfirmationValid = computed(
  () => deleteConfirmationText.value.trim() === DELETE_STAFF_CONFIRMATION
)

const sortBy = col => {
  if (sortCol.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortCol.value = col
    sortDir.value = 'asc'
  }
}

const clearSearch = () => {
  search.value = ''
}

const getRoleColor = role => {
  const normalizedRole = role ? role.toLowerCase() : ''

  if (normalizedRole.includes('admin')) {
    return 'bg-blue-100 text-blue-700 border border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800'
  }
  if (normalizedRole.includes('finanzas') || normalizedRole.includes('contabil')) {
    return 'bg-cyan-100 text-cyan-700 border border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800'
  }
  if (normalizedRole.includes('tec')) {
    return 'bg-purple-100 text-purple-700 border border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800'
  }
  if (normalizedRole.includes('staff')) {
    return 'bg-indigo-100 text-indigo-700 border border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:border-indigo-800'
  }
  if (normalizedRole.includes('almacen')) {
    return 'bg-orange-100 text-orange-700 border border-orange-200 dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800'
  }
  if (normalizedRole.includes('cliente')) {
    return 'bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800'
  }

  return 'bg-gray-100 text-gray-700 border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700'
}

const openDeleteModal = member => {
  memberToDelete.value = member
  deleteConfirmationText.value = ''
  showDeleteModal.value = true
}

const closeDeleteModal = () => {
  if (deletingUser.value) return

  showDeleteModal.value = false
  memberToDelete.value = null
  deleteConfirmationText.value = ''
}

const confirmDeleteUser = async () => {
  if (!memberToDelete.value) return

  if (!isDeleteConfirmationValid.value) {
    toast.value?.warning(
      'Confirmacion requerida',
      `Escribe ${DELETE_STAFF_CONFIRMATION} para continuar.`
    )
    return
  }

  deletingUser.value = true

  try {
    const response = await api.staff.delete(memberToDelete.value.id)

    if (response.data.success) {
      const deletedName = memberToDeleteName.value

      showDeleteModal.value = false
      memberToDelete.value = null
      deleteConfirmationText.value = ''

      toast.value?.success(
        'Staff eliminado',
        `El usuario ${deletedName} fue desactivado correctamente.`
      )

      await loadStaff()
    } else {
      toast.value?.error(
        'Error al eliminar',
        response.data.message || 'No se pudo eliminar el usuario.'
      )
    }
  } catch (error) {
    console.error('Error al desactivar usuario:', error.response?.data || error.message)
    toast.value?.error(
      'Error al eliminar',
      error.response?.data?.message || 'Error al desactivar el usuario. Por favor intenta nuevamente.'
    )
  } finally {
    deletingUser.value = false
  }
}

const formatDate = dateStr => {
  if (!dateStr) return '-'

  const date = new Date(dateStr)

  return date.toLocaleString('es-CO', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

const generateCSV = (withBOM = false) => {
  if (sortedStaff.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar.'
    )
    return null
  }

  const headers = ['Nombre', 'Usuario', 'Rol', 'Creado', 'Ultimo Acceso']

  const rows = sortedStaff.value.map(member => [
    `"${buildMemberName(member)}"`,
    `"${member.email_tenant || ''}"`,
    `"${member.role_name || ''}"`,
    `"${formatDate(member.create_at)}"`,
    `"${member.last_access ? formatDate(member.last_access) : '-'}"`,
  ])

  const csvContent = [headers.join(','), ...rows.map(row => row.join(','))].join('\n')

  return withBOM ? `\uFEFF${csvContent}` : csvContent
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

const exportToCSV = () => {
  const content = generateCSV(false)
  const date = new Date().toISOString().split('T')[0]
  downloadFile(content, `staff_list_${date}.csv`, 'text/csv;charset=utf-8;')
}

const exportToExcel = () => {
  if (sortedStaff.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar.'
    )
    return
  }

  const data = sortedStaff.value.map(member => ({
    Nombre: buildMemberName(member),
    Usuario: member.email_tenant || '',
    Rol: member.role_name || '',
    Creado: formatDate(member.create_at),
    'Ultimo Acceso': member.last_access ? formatDate(member.last_access) : '-',
  }))

  const worksheet = XLSX.utils.json_to_sheet(data)

  worksheet['!cols'] = [
    { wch: 25 },
    { wch: 30 },
    { wch: 20 },
    { wch: 20 },
    { wch: 20 },
  ]

  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Personal')

  const date = new Date().toISOString().split('T')[0]
  XLSX.writeFile(workbook, `staff_excel_${date}.xlsx`)
}
</script>
