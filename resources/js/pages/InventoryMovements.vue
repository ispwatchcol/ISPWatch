<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">

      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
          <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
            <v-icon name="bi-clock-history" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
          </div>
          Movimientos de inventario
        </h1>
        <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
          Historial completo: quién recibió cada equipo, en qué instalación se usó y cuándo.
        </p>
      </div>

      <!-- Filtros -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Persona o bodega</label>
            <select v-model="filters.holder" @change="reload"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                     focus:ring-2 focus:ring-purple-500 outline-none">
              <option value="">Todas</option>
              <option v-for="opt in holderOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tipo</label>
            <select v-model="filters.type" @change="reload"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                     focus:ring-2 focus:ring-purple-500 outline-none">
              <option value="">Todos</option>
              <option value="entrada">Entrada</option>
              <option value="traspaso">Traspaso</option>
              <option value="instalacion">Instalación</option>
              <option value="devolucion">Devolución</option>
              <option value="baja">Baja</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Desde</label>
            <input v-model="filters.from" @change="reload" type="date"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                     focus:ring-2 focus:ring-purple-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Hasta</label>
            <input v-model="filters.to" @change="reload" type="date"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                     focus:ring-2 focus:ring-purple-500 outline-none" />
          </div>
        </div>
      </div>

      <!-- Tabla -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <div v-if="loading" class="flex items-center justify-center py-16 text-gray-500 dark:text-gray-400">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando movimientos…
        </div>

        <div v-else-if="!movements.length" class="text-center py-16">
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="bi-clock-history" class="w-10 h-10 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin movimientos</p>
          <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">
            Los movimientos aparecen al entregar equipos o al usarlos en una instalación.
          </p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">Fecha</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">Tipo</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">Equipo / material</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">De</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">A</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase">Registró</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="m in movements" :key="m.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-600 dark:text-gray-300">
                  {{ fmtDate(m.created_at) }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span class="text-[11px] font-medium px-2 py-1 rounded-full" :class="typeClass(m.type)">
                    {{ typeLabel(m.type) }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <p class="text-sm text-gray-800 dark:text-white">
                    <span v-if="!m.serial" class="font-semibold">{{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }} · </span>
                    {{ m.item }}
                  </p>
                  <p v-if="m.serial" class="text-[11px] text-gray-500 dark:text-gray-400 font-mono">S/N {{ m.serial }}</p>
                  <p v-if="m.notes" class="text-[11px] text-gray-400 dark:text-gray-500 italic">{{ m.notes }}</p>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ m.from }}</td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                  {{ m.to }}
                  <RouterLink v-if="m.installation_id" :to="`/installations/${m.installation_id}`"
                    class="block text-[11px] text-indigo-600 dark:text-indigo-400 underline">
                    Orden #{{ m.installation_id }}
                  </RouterLink>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ m.created_by || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <div v-if="lastPage > 1" class="px-4 py-4 border-t border-gray-200 dark:border-gray-700
                    flex items-center justify-between gap-4 bg-gray-50 dark:bg-gray-900/50">
          <span class="text-sm text-gray-600 dark:text-gray-300">Página {{ page }} de {{ lastPage }}</span>
          <div class="flex gap-2">
            <button @click="goTo(page - 1)" :disabled="page <= 1"
              class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-gray-600
                     text-gray-700 dark:text-gray-300 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
              Anterior
            </button>
            <button @click="goTo(page + 1)" :disabled="page >= lastPage"
              class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-gray-600
                     text-gray-700 dark:text-gray-300 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
              Siguiente
            </button>
          </div>
        </div>
      </div>

      <NotificationToast ref="toast" />
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import inventoryApi from '@/services/api/inventory'
import inventoryBranchApi from '@/services/api/inventory-branch'
import catalogsApi from '@/services/api/catalogs'
import NotificationToast from '@/components/NotificationToast.vue'

const toast = ref(null)
const loading = ref(false)

const movements = ref([])
const page = ref(1)
const lastPage = ref(1)

const branches = ref([])
const staff = ref([])

const filters = ref({ holder: '', type: '', from: '', to: '' })

const holderOptions = computed(() => {
  const options = []
  for (const b of branches.value) options.push({ key: `branch:${b.id}`, label: b.name || `Sucursal #${b.id}` })
  for (const u of staff.value) options.push({ key: `user:${u.id}`, label: u.name })
  return options
})

const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

const fmtDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString('es-CO', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

const typeLabel = (type) => ({
  entrada: 'Entrada',
  traspaso: 'Traspaso',
  instalacion: 'Instalación',
  devolucion: 'Devolución',
  baja: 'Baja',
}[type] ?? type)

const typeClass = (type) => ({
  entrada:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
  traspaso:    'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
  instalacion: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
  devolucion:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
  baja:        'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
}[type] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300')

const load = async () => {
  loading.value = true
  try {
    const params = { page: page.value, per_page: 50 }
    if (filters.value.type) params.type = filters.value.type
    if (filters.value.from) params.from = filters.value.from
    if (filters.value.to)   params.to   = filters.value.to
    if (filters.value.holder) {
      const [type, id] = filters.value.holder.split(':')
      params.holder_type = type
      params.holder_id = Number(id)
    }

    const { data } = await inventoryApi.movements(params)
    movements.value = data.data ?? []
    lastPage.value = data.last_page ?? 1
  } catch (e) {
    toast.value?.error('Error', e.response?.data?.message || 'No se pudo cargar el historial.')
  } finally {
    loading.value = false
  }
}

const reload = () => {
  page.value = 1
  load()
}

const goTo = (target) => {
  if (target < 1 || target > lastPage.value) return
  page.value = target
  load()
}

onMounted(async () => {
  const [branchRes, staffRes] = await Promise.allSettled([
    inventoryBranchApi.getAll(),
    catalogsApi.getUsers({ staff: 1 }),
  ])
  if (branchRes.status === 'fulfilled') branches.value = branchRes.value.data || []
  if (staffRes.status === 'fulfilled')  staff.value    = staffRes.value.data || []
  await load()
})
</script>
