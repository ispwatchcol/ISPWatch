<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Sidebar -->
    <Sidebar />

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
        <button
          @click="$router.push('/staff/new')"
          class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center gap-2 transition-all"
        >
          <v-icon name="pr-plus" class="w-4 h-4" />
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
            placeholder="Buscar por nombre, usuario o nivel..."
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
                <th class="py-3 px-4 text-left">Usuario</th>
                <th class="py-3 px-4 text-left">Cargo</th>
                <th class="py-3 px-4 text-left">Creado</th>
                <th class="py-3 px-4 text-left">Último Acceso</th>
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

// Estados
const search = ref('')
const staff = ref([])

// Cargar datos del staff desde Supabase
const loadStaff = async () => {
  const sessionData = JSON.parse(localStorage.getItem("userData")) || JSON.parse(sessionStorage.getItem("userData"));
  const tenantId = sessionData?.tenant_id;

  if (!tenantId) {
    console.error("❌ No se encontró tenant_id en la sesión del usuario.");
    return;
  }

  const { data, error } = await supabase
    .from('user')
    .select('id, user_name, user_lastname, email_tenant, create_at, last_access, role:role_id (name)')
    .eq('tenant_id', tenantId) // 🔹 Solo los del mismo tenant
    .order('id', { ascending: true });


  if (error) {
    console.error('❌ Error al cargar staff:', error.message)
    return
  }

  // Mapear roles correctamente
  staff.value = data.map(u => ({
    ...u,
    role_name: u.role?.name || 'Sin rol'
  }))
}

onMounted(loadStaff)

// Filtrar búsqueda
const filteredStaff = computed(() =>
  staff.value.filter(member =>
    [member.user_name, member.user_lastname, member.email_tenant, member.role_name]
      .filter(Boolean)
      .some(f => f.toLowerCase().includes(search.value.toLowerCase()))
  )
)

const clearSearch = () => (search.value = '')

// Colores por rol
const getRoleColor = role => {
  switch (role) {
    case 'Administrador':
      return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'
    case 'Finanzas':
      return 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300'
    case 'Técnico':
      return 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300'
    default:
      return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'
  }
}

// Formato de fecha
const formatDate = dateStr => {
  if (!dateStr) return '—'
  const date = new Date(dateStr)
  return date.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

