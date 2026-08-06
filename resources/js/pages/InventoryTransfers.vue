<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">

      <!-- Header -->
      <div class="mb-8">
        <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
          <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
            <v-icon name="bi-arrow-left-right" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
          </div>
          Entregas y traspasos
        </h1>
        <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
          Entrega equipos a un técnico o recíbelos de vuelta. Cada movimiento queda registrado.
        </p>
      </div>

      <!-- Origen y destino -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sale de</label>
            <select v-model="fromKey" @change="loadHoldings"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-purple-500 outline-none">
              <option v-for="opt in holderOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Entra a</label>
            <select v-model="toKey"
              class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                     bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                     focus:ring-2 focus:ring-purple-500 outline-none">
              <option :value="null">— Selecciona destino —</option>
              <option v-for="opt in destinationOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div class="mt-4">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nota (opcional)</label>
          <input v-model="notes" type="text" maxlength="255" placeholder="Ej: entrega de la semana, devolución de sobrantes…"
            class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                   focus:ring-2 focus:ring-purple-500 outline-none" />
        </div>
      </div>

      <!-- Lo que tiene el origen -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="flex items-center justify-between gap-3 mb-4">
          <h2 class="text-base font-bold text-gray-800 dark:text-white">
            Disponible en {{ fromLabel }}
          </h2>
          <span v-if="selectedCount" class="text-xs text-purple-600 dark:text-purple-400 font-medium">
            {{ selectedCount }} seleccionado(s)
          </span>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-10 text-gray-500 dark:text-gray-400">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando…
        </div>

        <template v-else>
          <!-- Equipos con serial -->
          <div v-if="devices.length" class="mb-6">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Equipos con serial</p>
            <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
              <label v-for="d in devices" :key="d.id"
                class="flex items-center gap-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg
                       hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer transition">
                <input type="checkbox" :value="d.id" v-model="selectedDeviceIds" class="accent-purple-600 w-4 h-4" />
                <span class="min-w-0">
                  <span class="block text-sm text-gray-800 dark:text-white truncate">{{ d.item }}</span>
                  <span class="block text-[11px] text-gray-500 dark:text-gray-400 font-mono">
                    {{ d.serial || 'sin serial' }}<span v-if="d.mac"> · {{ d.mac }}</span>
                  </span>
                </span>
              </label>
            </div>
          </div>

          <!-- Materiales por cantidad -->
          <div v-if="materials.length">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Materiales</p>
            <div class="space-y-2">
              <div v-for="m in materials" :key="m.stock_id"
                class="flex items-center justify-between gap-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg">
                <div class="min-w-0">
                  <p class="text-sm text-gray-800 dark:text-white truncate">{{ m.item }}</p>
                  <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    Disponible: {{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }}
                  </p>
                </div>
                <input v-model.number="materialQty[m.stock_id]" type="number" min="0" :max="m.quantity" step="0.01"
                  placeholder="0"
                  class="w-24 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                         bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                         focus:ring-2 focus:ring-purple-500 outline-none" />
              </div>
            </div>
          </div>

          <p v-if="!devices.length && !materials.length" class="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
            {{ fromLabel }} no tiene existencias.
          </p>
        </template>
      </div>

      <!-- Acción -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-8">
        <button @click="submitTransfer" :disabled="!canSubmit || saving"
          class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800
                 disabled:opacity-50 disabled:cursor-not-allowed text-white px-6 py-3 rounded-xl
                 font-medium shadow-lg transition-all">
          {{ saving ? 'Registrando…' : 'Registrar entrega' }}
        </button>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          El equipo cambia de custodio y queda la línea en el historial. Nada se borra.
        </p>
      </div>

      <!-- Entrada de material nuevo (compra) -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-1">Entrada de material</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
          Para dar de alta consumibles comprados (RJ45, cable, platos). Los equipos con serial se
          registran en
          <RouterLink to="/inventory/create" class="underline">Agregar equipo</RouterLink>.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <select v-model.number="entry.stock_id"
            class="md:col-span-2 px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                   focus:ring-2 focus:ring-purple-500 outline-none">
            <option :value="null">— Material —</option>
            <option v-for="s in consumableStocks" :key="s.id" :value="s.id">
              {{ `${s.brand ?? ''} ${s.model ?? ''}`.trim() }}{{ s.unit ? ` (${s.unit})` : '' }}
            </option>
          </select>
          <input v-model.number="entry.quantity" type="number" min="0.01" step="0.01" placeholder="Cantidad"
            class="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                   focus:ring-2 focus:ring-purple-500 outline-none" />
          <select v-model="entry.toKey"
            class="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl
                   bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                   focus:ring-2 focus:ring-purple-500 outline-none">
            <option :value="null">— Entra a —</option>
            <option v-for="opt in destinationOptions" :key="opt.key" :value="opt.key">{{ opt.label }}</option>
          </select>
        </div>

        <p v-if="!consumableStocks.length" class="mt-3 text-xs text-amber-600 dark:text-amber-400">
          No hay modelos marcados «por cantidad». Créalos en
          <RouterLink to="/inventory/stocks" class="underline font-medium">Stock / Modelos</RouterLink>
          eligiendo «Por cantidad».
        </p>

        <button @click="submitEntry" :disabled="!canSubmitEntry || saving"
          class="mt-4 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white px-5 py-2.5
                 rounded-xl text-sm font-medium transition">
          Registrar entrada
        </button>
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
import inventoryStockApi from '@/services/api/inventory-stock'
import catalogsApi from '@/services/api/catalogs'
import NotificationToast from '@/components/NotificationToast.vue'

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)

const branches = ref([])
const staff = ref([])
const stocks = ref([])

// Origen y destino se manejan con una clave "tipo:id" porque un <select> sólo
// guarda un valor y el custodio son dos datos (si es sucursal o persona, y cuál).
const fromKey = ref('branch:')
const toKey = ref(null)
const notes = ref('')

const devices = ref([])
const materials = ref([])
const selectedDeviceIds = ref([])
const materialQty = ref({})

const entry = ref({ stock_id: null, quantity: null, toKey: null })

const parseKey = (key) => {
  if (!key) return null
  const [type, rawId] = key.split(':')
  return { type, id: rawId === '' ? null : Number(rawId) }
}

const holderOptions = computed(() => {
  const options = [{ key: 'branch:', label: 'Bodega (sin sucursal)' }]
  for (const b of branches.value) options.push({ key: `branch:${b.id}`, label: b.name || `Sucursal #${b.id}` })
  for (const u of staff.value) options.push({ key: `user:${u.id}`, label: u.name })
  return options
})

// El destino no admite "sin sucursal": entregar a una bodega que no existe deja
// existencias sin sitio al que volver.
const destinationOptions = computed(() => holderOptions.value.filter(o => o.key !== 'branch:'))

const fromLabel = computed(() =>
  holderOptions.value.find(o => o.key === fromKey.value)?.label ?? 'el origen'
)

const consumableStocks = computed(() => stocks.value.filter(s => s.is_serialized === false))

const selectedMaterials = computed(() =>
  Object.entries(materialQty.value)
    .map(([stockId, qty]) => ({ stock_id: Number(stockId), quantity: Number(qty) || 0 }))
    .filter(m => m.quantity > 0)
)

const selectedCount = computed(() => selectedDeviceIds.value.length + selectedMaterials.value.length)

const canSubmit = computed(() =>
  !!toKey.value && toKey.value !== fromKey.value && selectedCount.value > 0
)

const canSubmitEntry = computed(() =>
  !!entry.value.stock_id && Number(entry.value.quantity) > 0 && !!entry.value.toKey
)

const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

const loadCatalogs = async () => {
  const [branchRes, staffRes, stockRes] = await Promise.allSettled([
    inventoryBranchApi.getAll(),
    catalogsApi.getUsers({ staff: 1 }),
    inventoryStockApi.getAll(),
  ])
  if (branchRes.status === 'fulfilled') branches.value = branchRes.value.data || []
  if (staffRes.status === 'fulfilled')  staff.value    = staffRes.value.data || []
  if (stockRes.status === 'fulfilled')  stocks.value   = stockRes.value.data || []
}

const loadHoldings = async () => {
  const holder = parseKey(fromKey.value)
  if (!holder) return

  loading.value = true
  selectedDeviceIds.value = []
  materialQty.value = {}
  try {
    const { data } = await inventoryApi.holdings({
      holder_type: holder.type,
      holder_id: holder.id ?? undefined,
    })
    devices.value = data.devices || []
    materials.value = data.materials || []
  } catch (e) {
    toast.value?.error('Error', firstError(e) || 'No se pudieron cargar las existencias.')
  } finally {
    loading.value = false
  }
}

const submitTransfer = async () => {
  const to = parseKey(toKey.value)
  const from = parseKey(fromKey.value)
  if (!to) return

  saving.value = true
  try {
    await inventoryApi.transfer({
      to_type: to.type,
      to_id: to.id,
      device_ids: selectedDeviceIds.value,
      materials: selectedMaterials.value.map(m => ({
        ...m,
        source_type: from.type,
        source_id: from.id,
      })),
      notes: notes.value || null,
    })
    toast.value?.success('Entrega registrada', 'Las existencias cambiaron de custodio.')
    notes.value = ''
    await loadHoldings()
  } catch (e) {
    toast.value?.error('Error', firstError(e) || 'No se pudo registrar la entrega.')
  } finally {
    saving.value = false
  }
}

const submitEntry = async () => {
  const to = parseKey(entry.value.toKey)
  if (!to) return

  saving.value = true
  try {
    // Sin source_* el backend lo registra como ENTRADA desde el proveedor.
    await inventoryApi.transfer({
      to_type: to.type,
      to_id: to.id,
      materials: [{ stock_id: entry.value.stock_id, quantity: Number(entry.value.quantity) }],
      notes: notes.value || 'Entrada de material',
    })
    toast.value?.success('Entrada registrada', 'El material ya está disponible.')
    entry.value = { stock_id: null, quantity: null, toKey: null }
    await loadHoldings()
  } catch (e) {
    toast.value?.error('Error', firstError(e) || 'No se pudo registrar la entrada.')
  } finally {
    saving.value = false
  }
}

const firstError = (e) => {
  const errors = e.response?.data?.errors
  if (errors) {
    const first = Object.values(errors)[0]
    if (Array.isArray(first) && first.length) return first[0]
  }
  return e.response?.data?.message
}

onMounted(async () => {
  await loadCatalogs()
  await loadHoldings()
})
</script>
