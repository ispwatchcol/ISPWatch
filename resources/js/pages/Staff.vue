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
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">{{ member.name }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ member.email }}</td>
                <td>
                  <span
                    class="inline-block px-3 py-1 text-xs font-semibold rounded-full"
                    :class="getRoleColor(member.role)"
                  >
                    {{ member.role }}
                  </span>
                </td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">{{ member.created }}</td>
                <td class="py-3 px-4 text-gray-500 dark:text-gray-400">{{ member.lastAccess }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Modal Nuevo Staff -->
      <div
        v-if="openModal"
        class="fixed inset-0 bg-black/40 flex items-center justify-center z-50"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-6 shadow-xl w-full max-w-md">
          <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">
            Agregar nuevo Staff
          </h2>

          <div class="space-y-4">
            <input
              v-model="newMember.name"
              placeholder="Nombre completo"
              class="w-full border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 dark:bg-gray-900 dark:text-white"
            />
            <input
              v-model="newMember.email"
              placeholder="Correo electrónico"
              class="w-full border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 dark:bg-gray-900 dark:text-white"
            />
            <select
              v-model="newMember.role"
              class="w-full border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-2 dark:bg-gray-900 dark:text-white"
            >
              <option disabled value="">Seleccionar Cargo</option>
              <option>Administrador</option>
              <option>Finanzas</option>
              <option>Técnico</option>
            </select>
          </div>

          <div class="flex justify-end gap-3 mt-6">
            <button
              @click="openModal = false"
              class="px-4 py-2 bg-gray-200 dark:bg-gray-700 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600"
            >
              Cancelar
            </button>
            <button
              @click="addStaff"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg"
            >
              Guardar
            </button>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const openModal = ref(false)
const search = ref('')
const staff = ref([
  {
    id: 1,
    name: 'Carolina González',
    email: 'contabilidadcolombianet@colombia-net-tolima',
    role: 'Finanzas',
    created: '02/10/2024 16:55',
    lastAccess: '15/10/2025 17:42'
  },
  {
    id: 2,
    name: 'William Cortes',
    email: 'william.cortes@colombia-net-tolima',
    role: 'Administrador',
    created: '02/10/2024 17:00',
    lastAccess: '30/09/2025 11:41'
  },
  {
    id: 3,
    name: 'Nelly Hernández',
    email: 'nelly02.hernandez@colombia-net-tolima',
    role: 'Técnico',
    created: '17/01/2025 20:24',
    lastAccess: '04/10/2025 09:18'
  }
])

const newMember = ref({
  name: '',
  email: '',
  role: ''
})

const filteredStaff = computed(() =>
  staff.value.filter(member =>
    [member.name, member.email, member.role].some(f =>
      f.toLowerCase().includes(search.value.toLowerCase())
    )
  )
)

const addStaff = () => {
  if (!newMember.value.name || !newMember.value.email || !newMember.value.role) return
  staff.value.push({
    id: staff.value.length + 1,
    ...newMember.value,
    created: new Date().toLocaleString(),
    lastAccess: '—'
  })
  openModal.value = false
  newMember.value = { name: '', email: '', role: '' }
}

const clearSearch = () => (search.value = '')

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
</script>
