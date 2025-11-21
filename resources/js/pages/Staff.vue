<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">

    <!-- Contenido principal -->
    <main class="flex-1 p-6 overflow-y-auto">
      <!-- Encabezado -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="pr-users" class="text-blue-600 w-7 h-7" />
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
        <div class="flex items-center justify-between mb-4">
          <input
            v-model="search"
            type="text"
            placeholder="Buscar por nombre, usuario o rol"
            class="border border-gray-300 dark:border-gray-700 rounded-xl px-4 py-2 w-80 focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white"
          />
          <button
            @click="clearSearch"
            class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all"
          >
            Limpiar
          </button>
        </div>

        <!-- Loading state -->
        <div v-if="loading" class="text-center py-8">
          <p class="text-gray-500 dark:text-gray-400">Cargando personal...</p>
        </div>

        <!-- Tabla -->
        <div class="overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm uppercase tracking-wide">
                <th class="py-3 px-4 text-left">Nombre</th>
                <th class="py-3 px-4 text-left">Usuario</th>
                <th class="py-3 px-4 text-left">Rol</th>
                <th class="py-3 px-4 text-left">Creado</th>
                <th class="py-3 px-4 text-left">Último Acceso</th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="member in filteredStaff"
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
          <div v-if="filteredStaff.length === 0" class="text-center py-8">
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

//  reactive states
const search = ref('')
const staff = ref([])
const tenantId = ref(null)
const loading = ref(false)

// load staff data from API
const loadStaff = async () => {
  try {
    // get user sesion from localStorage or sessionStorage
    const sessionData =
      JSON.parse(localStorage.getItem("userData")) ||
      JSON.parse(sessionStorage.getItem("userData"))

    tenantId.value = sessionData?.tenant_id

    if (!tenantId.value) {
      console.error("❌ No se encontró tenant_id en la sesión del usuario.")
      alert("No se encontró información del tenant. Por favor inicia sesión nuevamente.")
      return
    }

    // call API to get staff data
    const response = await api.staff.getAll({ tenant_id: tenantId.value })

    if (response.data.success) {
      staff.value = response.data.data
    } else {
      console.error("Error en respuesta: ", response.data)
      alert("Error al cargar el personal")
    }
  } catch (error) {
    console.error("⚠️ Error al cargar staff:", error.response?.data || error.message)
    alert("Error al cargar el personal. Por favor intenta nuevamente.")
  } finally {
    loading.value = false
  }
}

onMounted(loadStaff)

// search filter
const filteredStaff = computed(() =>
  staff.value.filter(member =>
    [member.user_name, member.user_lastname, member.email_tenant, member.role_name]
      .filter(Boolean)
      .some(f => f.toLowerCase().includes(search.value.toLowerCase()))
  )
)

// clear search input
const clearSearch = () => (search.value = "")

// dynamic role color classes
const getRoleColor = (role) => {
  switch (role) {
    case "Administrador":
      return "bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
    case "Finanzas":
      return "bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300"
    case "Técnico":
      return "bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
    default:
      return "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
  }
}

// disable user (soft delete)
const deleteUser = async (id) => {
  if (!confirm("¿Seguro que deseas desactivar este usuario?")) return
  
  try {
    const response = await api.staff.delete(id)

    if (response.data.success) {
      alert("✅ Usuario desactivado correctamente.")
      await loadStaff() // reload staff list after deletion
    }
  } catch (erorr) {
      console.error("❌ Error al desactivar usuario:", error.response?.data || error.message)
      alert("Error al desactivar el usuario. Por favor intenta nuevamente.")
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
</script>
