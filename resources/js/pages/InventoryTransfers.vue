<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">

      <!-- Header -->
      <div class="mb-6">
        <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
          <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
            <v-icon name="bi-arrow-left-right" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
          </div>
          Entregas y traspasos
        </h1>
        <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
          Entrega equipos y material a un técnico o recíbelos de vuelta. Cada movimiento queda registrado.
          ¿Llegó mercancía nueva? Eso va en
          <RouterLink :to="{ name: 'InventoryIntake' }" class="underline">Ingresar al inventario</RouterLink>.
        </p>
      </div>

      <!-- Origen y destino -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-3 md:items-end">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Sale de</label>
            <SearchableSelect v-model="fromKey" :items="holderOptions" item-key="key" item-label="label"
              item-sublabel="kind" item-icon="md-storemalldirectory" placeholder="Bodega o persona…"
              search-placeholder="Buscar bodega o persona…" data-testid="transfer-from" />
          </div>
          <!-- Invertir: recibir los sobrantes del técnico es la entrega al revés. -->
          <button type="button" @click="swap" :disabled="!canSwap" data-testid="transfer-swap"
            title="Invertir: lo que entra pasa a ser el origen"
            class="justify-self-center md:mb-0.5 inline-flex items-center gap-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
            <v-icon name="bi-arrow-left-right" class="w-4 h-4" />
            <span class="md:hidden">Invertir</span>
          </button>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Entra a</label>
            <SearchableSelect v-model="toKey" :items="destinationOptions" item-key="key" item-label="label"
              item-sublabel="kind" item-icon="md-storemalldirectory" placeholder="— Selecciona destino —"
              search-placeholder="Buscar bodega o persona…" data-testid="transfer-to"
              :invalid="!!toKey && toKey === fromKey" />
            <p v-if="toKey && toKey === fromKey" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
              El destino es el mismo origen.
            </p>
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
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <h2 class="text-base font-bold text-gray-800 dark:text-white">
            Disponible en {{ fromLabel }}
          </h2>
          <span v-if="selectedCount" class="text-xs text-purple-600 dark:text-purple-400 font-medium">
            {{ selectedCount }} seleccionado(s)
          </span>
        </div>

        <!-- Con decenas de ONU en bodega, se busca el serial en vez de recorrer la lista. -->
        <div v-if="devices.length + materials.length > 5" class="relative mb-4">
          <v-icon name="io-search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
          <input v-model="filter" type="search" placeholder="Filtrar por serial, MAC, modelo o material…" data-testid="transfer-filter"
            class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg
                   bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 outline-none" />
        </div>

        <div v-if="loading" class="flex items-center justify-center py-10 text-gray-500 dark:text-gray-400">
          <v-icon name="ri-loader-4-line" animation="spin" class="w-6 h-6 mr-2" /> Cargando…
        </div>

        <template v-else>
          <!-- Equipos con serial -->
          <div v-if="devices.length" class="mb-6">
            <div class="flex items-center justify-between gap-3 mb-2">
              <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">
                Equipos con serial ({{ visibleDevices.length }}{{ filter ? ` de ${devices.length}` : '' }})
              </p>
              <label v-if="visibleDevices.length" class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
                <input type="checkbox" :checked="allVisibleSelected" @change="toggleAllVisible($event.target.checked)"
                  class="accent-purple-600 w-4 h-4" data-testid="transfer-select-all" />
                Todos{{ filter ? ' los filtrados' : '' }}
              </label>
            </div>
            <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
              <label v-for="d in visibleDevices" :key="d.id"
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
              <p v-if="!visibleDevices.length" class="text-sm text-gray-500 dark:text-gray-400 italic">Ningún equipo coincide con el filtro.</p>
            </div>
          </div>

          <!-- Materiales por cantidad -->
          <div v-if="materials.length">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Materiales</p>
            <div class="space-y-2">
              <div v-for="m in visibleMaterials" :key="m.stock_id"
                class="flex items-center justify-between gap-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg">
                <div class="min-w-0">
                  <p class="text-sm text-gray-800 dark:text-white truncate">{{ m.item }}</p>
                  <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    Disponible: {{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }}
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <button type="button" @click="materialQty[m.stock_id] = Number(m.quantity)"
                    class="text-xs text-purple-700 dark:text-purple-300 hover:underline" title="Pasar todo lo disponible">Todo</button>
                  <input v-model.number="materialQty[m.stock_id]" type="number" min="0" :max="m.quantity" :step="quantityStep(m)"
                    placeholder="0" onwheel="this.blur()"
                    class="w-24 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                           bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
                           focus:ring-2 focus:ring-purple-500 outline-none" />
                </div>
              </div>
              <p v-if="!visibleMaterials.length" class="text-sm text-gray-500 dark:text-gray-400 italic">Ningún material coincide con el filtro.</p>
            </div>
          </div>

          <p v-if="!devices.length && !materials.length" class="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
            {{ fromLabel }} no tiene existencias.
            <RouterLink :to="{ name: 'InventoryIntake' }" class="underline">Ingresar mercancía</RouterLink>
          </p>
        </template>
      </div>

      <!-- Acción -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-8">
        <button @click="submitTransfer" :disabled="!canSubmit || saving" data-testid="transfer-submit"
          class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800
                 disabled:opacity-50 disabled:cursor-not-allowed text-white px-6 py-3 rounded-xl
                 font-medium shadow-lg transition-all">
          {{ saving ? 'Registrando…' : `Registrar entrega${selectedCount ? ` (${selectedCount})` : ''}` }}
        </button>
        <p class="text-xs text-gray-500 dark:text-gray-400">
          El equipo cambia de custodio y queda la línea en el historial. Nada se borra; si algo falla, no se mueve nada.
        </p>
      </div>

      <NotificationToast ref="toast" />
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import inventoryApi from '@/services/api/inventory'
import inventoryBranchApi from '@/services/api/inventory-branch'
import catalogsApi from '@/services/api/catalogs'
import { firstError } from '@/utils/apiError'
import { quantityStep } from '@/utils/materialQuantity'
import NotificationToast from '@/components/NotificationToast.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

/**
 * Entregas y traspasos: mover lo que YA está en el inventario de un custodio a
 * otro. La mercancía nueva entra por «Ingresar al inventario» (antes había una
 * «Entrada de material» al pie de esta página; los enlaces viejos `?entrada=`
 * los redirige el router).
 */

const toast = ref(null)
const loading = ref(false)
const saving = ref(false)

const branches = ref([])
const staff = ref([])

// Origen y destino se manejan con una clave "tipo:id" porque el desplegable sólo
// guarda un valor y el custodio son dos datos (si es sucursal o persona, y cuál).
const fromKey = ref('branch:')
const toKey = ref(null)
const notes = ref('')

const devices = ref([])
const materials = ref([])
const selectedDeviceIds = ref([])
const materialQty = ref({})
const filter = ref('')

const parseKey = (key) => {
  if (!key) return null
  const [type, rawId] = key.split(':')
  return { type, id: rawId === '' ? null : Number(rawId) }
}

const holderOptions = computed(() => {
  const options = [{ key: 'branch:', label: 'Bodega (sin sucursal)', kind: 'Sin ubicar' }]
  for (const b of branches.value) options.push({ key: `branch:${b.id}`, label: b.name || `Sucursal #${b.id}`, kind: 'Sucursal / bodega' })
  for (const u of staff.value) options.push({ key: `user:${u.id}`, label: u.name, kind: 'Persona' })
  return options
})

// El destino no admite "sin sucursal": entregar a una bodega que no existe deja
// existencias sin sitio al que volver.
const destinationOptions = computed(() => holderOptions.value.filter(o => o.key !== 'branch:'))

const fromLabel = computed(() =>
  holderOptions.value.find(o => o.key === fromKey.value)?.label ?? 'el origen'
)

// Invertir sólo si el destino actual sirve de origen y el origen de destino.
const canSwap = computed(() => !!toKey.value && fromKey.value !== 'branch:' && toKey.value !== fromKey.value)
const swap = () => {
  if (!canSwap.value) return
  const from = fromKey.value
  fromKey.value = toKey.value
  toKey.value = from
}

// Sin tildes ni separadores de serial/MAC: «aabb» encuentra «AA:BB».
const fold = (v) => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const compact = (v) => fold(v).replace(/[\s:.\-_/]/g, '')
const matches = (texts) => {
  const terms = fold(filter.value).split(/\s+/).filter(Boolean)
  if (!terms.length) return true
  const words = fold(texts.filter(Boolean).join(' '))
  const flat = compact(texts.filter(Boolean).join(' '))
  return terms.every(t => words.includes(t) || flat.includes(compact(t)))
}
const visibleDevices = computed(() => devices.value.filter(d => matches([d.item, d.serial, d.mac])))
const visibleMaterials = computed(() => materials.value.filter(m => matches([m.item])))

const allVisibleSelected = computed(() =>
  visibleDevices.value.length > 0 && visibleDevices.value.every(d => selectedDeviceIds.value.includes(d.id))
)
const toggleAllVisible = (checked) => {
  const visible = new Set(visibleDevices.value.map(d => d.id))
  const rest = selectedDeviceIds.value.filter(id => !visible.has(id))
  selectedDeviceIds.value = checked ? [...rest, ...visible] : rest
}

const selectedMaterials = computed(() =>
  Object.entries(materialQty.value)
    .map(([stockId, qty]) => ({ stock_id: Number(stockId), quantity: Number(qty) || 0 }))
    .filter(m => m.quantity > 0)
)

const selectedCount = computed(() => selectedDeviceIds.value.length + selectedMaterials.value.length)

const canSubmit = computed(() =>
  !!toKey.value && toKey.value !== fromKey.value && selectedCount.value > 0
)

const fmtQty = (n) => {
  const value = Number(n) || 0
  return Number.isInteger(value) ? String(value) : value.toFixed(2).replace('.', ',')
}

const loadCatalogs = async () => {
  const [branchRes, staffRes] = await Promise.allSettled([
    inventoryBranchApi.getAll(),
    catalogsApi.getUsers({ staff: 1 }),
  ])
  if (branchRes.status === 'fulfilled') branches.value = branchRes.value.data || []
  if (staffRes.status === 'fulfilled')  staff.value    = staffRes.value.data || []
}

const loadHoldings = async () => {
  const holder = parseKey(fromKey.value)
  if (!holder) return

  loading.value = true
  selectedDeviceIds.value = []
  materialQty.value = {}
  filter.value = ''
  try {
    const { data } = await inventoryApi.holdings({
      holder_type: holder.type,
      holder_id: holder.id ?? undefined,
    })
    devices.value = data.devices || []
    materials.value = data.materials || []
  } catch (e) {
    toast.value?.error('Error', firstError(e, 'No se pudieron cargar las existencias.'))
  } finally {
    loading.value = false
  }
}

// Cambiar el origen (a mano o con «Invertir») recarga lo que tiene.
watch(fromKey, (value, old) => { if (value !== old) loadHoldings() })

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
    toast.value?.error('No se movió nada', firstError(e, 'No se pudo registrar la entrega.'))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadCatalogs()
  await loadHoldings()
})
</script>
