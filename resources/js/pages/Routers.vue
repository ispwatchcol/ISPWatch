<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">

    <!-- Contenido principal -->
    <main class="flex-1 p-6 overflow-y-auto">
      <!-- Encabezado -->
      <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <v-icon name="pr-server" class="text-blue-600 w-7 h-7" />
              Routers del Sistema
            </h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
              Gestión de routers y configuración por zonas.
            </p>
        </div>
        <!-- Botón Agregar Router -->
          <button
            @click="goToAddRouter"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center gap-2 transition-all"
          >
            <icon-lucide-plus class="w-4 h-4" />
            Agregar Router
          </button>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-6">
        <!-- Filtros -->
        <div class="flex items-center justify-between mb-4">
          <input
            v-model="search"
            type="text"
            placeholder="Buscar por nombre, IP o usuario..."
            class="border border-gray-300 dark:border-gray-700 rounded-xl px-4 py-2 w-80 focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white"
          />
          <button
            @click="clearSearch"
            class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all"
          >
            Limpiar
          </button>
        </div>

        <!-- Tabla -->
        <div class="overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm uppercase tracking-wide">
                <th class="py-3 px-4 text-left">Nombre</th>
                <th class="py-3 px-4 text-left">IP</th>
                <th class="py-3 px-4 text-left">Usuario RB</th>
                <th class="py-3 px-4 text-left">Interfaz LAN</th>
                <th class="py-3 px-4 text-left">Versión Firmware</th>
                <th class="py-3 px-4 text-left">Estado</th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="router in filteredRouters"
                :key="router.id"
                class="border-b border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-700/40 transition-all"
              >
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">{{ router.name }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.ip }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.user_rb }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.lan_interface || '—' }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.firmware_version || '—' }}</td>
                <td class="py-3 px-4">
                  <span
                    class="inline-block px-3 py-1 text-xs font-semibold rounded-full"
                    :class="router.status === 'active'
                      ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                      : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                  >
                    {{ router.status || '—' }}
                  </span>
                </td>
                <td class="py-3 px-4 flex gap-2">
                  <!-- Botón Editar -->
                  <button
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-blue-50 text-blue-700 border border-blue-200
                          hover:bg-blue-100 hover:scale-[1.03] transition-all
                          dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                  >
                    <icon-lucide-pencil class="w-4 h-4" />
                    Editar
                  </button>

                  <!-- Botón Detalles -->
                  <button
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-cyan-50 text-cyan-700 border border-cyan-200
                          hover:bg-cyan-100 hover:scale-[1.03] transition-all
                          dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800 dark:hover:bg-cyan-800/50"
                  >
                    <icon-lucide-bar-chart-3 class="w-4 h-4" />
                    Detalles
                  </button>

                  <!-- Botón Eliminar -->
                  <button
                    @click="deleteRouter(router.id)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-red-50 text-red-700 border border-red-200
                          hover:bg-red-100 hover:scale-[1.03] transition-all
                          dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                  >
                    <icon-lucide-trash class="w-4 h-4" />
                    Eliminar
                  </button>
                </td>
              </tr>
              <tr v-if="filteredRouters.length === 0">
                <td colspan="7" class="text-center py-6 text-gray-500 dark:text-gray-400">
                  No se encontraron routers.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import { useRouter } from 'vue-router'

const router = useRouter()
const search = ref('')
const routers = ref([])

// 🔹 Navegar a la vista de agregar router
const goToAddRouter = () => {
  router.push('/routers/add')
}


// 🔹 Cargar routers desde Supabase filtrados por tenant
const loadRouters = async () => {
  // Obtener los datos del usuario almacenados
  const userData =
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))

  if (!userData || !userData.tenant_id) {
    console.error("⚠️ No se encontró tenant_id del usuario autenticado.")
    return
  }

  const tenant_id = userData.tenant_id

  // Consultar routers por tenant
  const { data, error } = await supabase
    .from("router")
    .select("id, name, ip, user_rb, lan_interface, firmware_version, status")
    .eq("tenant_id", tenant_id)

  if (error) {
    console.error("❌ Error al cargar routers:", error.message)
    return
  }

  routers.value = data || []
}


onMounted(loadRouters)

// 🔹 Eliminar router
const deleteRouter = async (id) => {
  if (!confirm('¿Estás seguro de eliminar este router?')) return

  const { error } = await supabase.from('router').delete().eq('id', id)

  if (error) {
    console.error('❌ Error al eliminar router:', error.message)
    alert('Error al eliminar el router.')
    return
  }

  routers.value = routers.value.filter(r => r.id !== id)
  alert('Router eliminado correctamente.')
}

// 🔹 Filtro de búsqueda
const filteredRouters = computed(() =>
  routers.value.filter(r =>
    [r.name, r.ip, r.user_rb, r.status]
      .filter(Boolean)
      .some(f => f.toLowerCase().includes(search.value.toLowerCase()))
  )
)

const clearSearch = () => (search.value = '')
</script>
