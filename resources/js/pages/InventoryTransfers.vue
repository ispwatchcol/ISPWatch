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
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">
              Materiales ({{ visibleMaterials.length }}{{ filter ? ` de ${materials.length}` : '' }})
            </p>
            <div class="space-y-2">
              <div v-for="m in visibleMaterials" :key="m.stock_id" data-testid="transfer-material"
                class="flex items-center justify-between gap-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg">
                <div class="min-w-0">
                  <p class="text-sm text-gray-800 dark:text-white truncate">{{ m.item }}</p>
                  <p class="text-[11px] text-gray-500 dark:text-gray-400">
                    Disponible: {{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }}
                    <!-- Bajar el saldo sin entregárselo a nadie: un ingreso
                         digitado de más, o material dañado/perdido. -->
                    · <button type="button" @click="openWriteOff(m)" data-testid="material-writeoff"
                        class="text-rose-700 dark:text-rose-300 hover:underline font-medium"
                        title="Quitar del saldo: se ingresó de más, se dañó, se perdió o volvió al proveedor">Descontar…</button>
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

          <!-- Los materiales están en otro origen. «Bodega (sin sucursal)» nunca
               tiene: un saldo siempre es de una bodega o de una persona. Sin este
               aviso parecía que la pantalla ya no manejaba materiales. -->
          <div v-if="!materials.length && otherMaterialHolders.length" data-testid="materials-elsewhere"
            class="mt-4 rounded-lg border border-purple-200 dark:border-purple-800 bg-purple-50 dark:bg-purple-900/20 p-3 text-sm">
            <p class="text-gray-700 dark:text-gray-200">
              <template v-if="fromKey === 'branch:'">
                Los materiales por cantidad siempre están a nombre de una bodega o de una persona.
              </template>
              <template v-else>{{ fromLabel }} no tiene materiales.</template>
              Hay materiales en:
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
              <button v-for="h in otherMaterialHolders" :key="h.key" type="button" @click="fromKey = h.key"
                :data-testid="`materials-at-${h.key}`"
                class="px-3 py-1 rounded-full text-xs font-medium bg-white dark:bg-gray-800 text-purple-700 dark:text-purple-300
                       border border-purple-300 dark:border-purple-700 hover:bg-purple-100 dark:hover:bg-purple-900/40">
                {{ h.label }} · {{ h.materials }} material{{ h.materials === 1 ? '' : 'es' }}
              </button>
            </div>
          </div>
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

      <!-- Descontar material: corregir un ingreso digitado de más, o dar de
           baja lo dañado, perdido o devuelto. Nada se borra: queda en Movimientos. -->
      <div v-if="writeOff" class="fixed inset-0 z-app-modal flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        @click.self="closeWriteOff">
        <form @submit.prevent="submitWriteOff" data-testid="writeoff-modal"
          class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6 max-h-[92vh] overflow-y-auto">
          <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <v-icon name="md-cancel-outlined" class="w-5 h-5 text-rose-600" />
            Descontar material
          </h2>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            <strong>{{ writeOff.item }}</strong> en {{ fromLabel }} · disponible
            {{ fmtQty(writeOff.available) }}{{ writeOff.unit ? ` ${writeOff.unit}` : '' }}
          </p>

          <fieldset class="mt-4">
            <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Motivo *</legend>
            <div class="space-y-1.5">
              <label v-for="r in WRITE_OFF_REASONS" :key="r.key"
                class="flex items-start gap-2 p-2 rounded-lg border cursor-pointer transition"
                :class="writeOff.reason === r.key
                  ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                  : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/40'">
                <input type="radio" :value="r.key" v-model="writeOff.reason" class="mt-0.5 accent-purple-600"
                  :data-testid="`writeoff-reason-${r.key}`" />
                <span>
                  <span class="block text-sm text-gray-800 dark:text-gray-100">{{ r.label }}</span>
                  <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ r.hint }}</span>
                </span>
              </label>
            </div>
          </fieldset>

          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mt-4 mb-1" for="writeoff-qty">
            Cantidad a descontar *
          </label>
          <div class="flex items-center gap-2">
            <input id="writeoff-qty" v-model.number="writeOff.quantity" type="number" min="0" :max="writeOff.available"
              :step="writeOff.step" placeholder="0" onwheel="this.blur()" data-testid="writeoff-quantity"
              class="w-36 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700
                     text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-purple-500 outline-none" />
            <span v-if="writeOff.unit" class="text-sm text-gray-500 dark:text-gray-400">{{ writeOff.unit }}</span>
          </div>
          <p class="mt-1 text-xs" :class="writeOffQuantityError ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400'"
            data-testid="writeoff-remaining">
            <template v-if="writeOffQuantityError && writeOff.quantity">{{ writeOffQuantityError }}</template>
            <template v-else>
              Quedarán {{ fmtQty(writeOffRemaining) }}{{ writeOff.unit ? ` ${writeOff.unit}` : '' }} en {{ fromLabel }}.
            </template>
          </p>

          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mt-4 mb-1" for="writeoff-notes">Nota (opcional)</label>
          <input id="writeoff-notes" v-model="writeOff.notes" type="text" maxlength="200" data-testid="writeoff-notes"
            placeholder="Ej: se digitó 500 en vez de 50"
            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700
                   text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 outline-none" />

          <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            No se borra nada: el saldo baja y queda en Movimientos con tu nombre y el motivo. Para pasarlo a
            otra bodega o técnico usa la entrega, no esto.
          </p>

          <p v-if="writeOffError" class="mt-3 text-sm text-rose-600 dark:text-rose-400" data-testid="writeoff-error">
            {{ writeOffError }}
          </p>

          <div class="flex justify-end gap-3 mt-5">
            <button type="button" @click="closeWriteOff" :disabled="writingOff"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg
                     hover:bg-gray-200 dark:hover:bg-gray-600">Cancelar</button>
            <button type="submit" :disabled="!canWriteOff || writingOff" data-testid="writeoff-confirm"
              class="px-4 py-2 text-sm font-medium text-white bg-rose-600 hover:bg-rose-700 rounded-lg
                     disabled:opacity-50 disabled:cursor-not-allowed">
              {{ writingOff ? 'Descontando…' : writeOffButtonLabel }}
            </button>
          </div>
        </form>
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
import { quantityStep, quantityError } from '@/utils/materialQuantity'
import { searchIndex, searchTerms, matchesIndex } from '@/utils/textSearch'
import NotificationToast from '@/components/NotificationToast.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

/**
 * Entregas y traspasos: mover lo que YA está en el inventario de un custodio a
 * otro. La mercancía nueva entra por «Ingresar al inventario» (antes había una
 * «Entrada de material» al pie de esta página; los enlaces viejos `?entrada=`
 * los redirige el router).
 */

const toast = ref(null)
const loading = ref(true)
const saving = ref(false)

const branches = ref([])
const staff = ref([])

// Cuántos equipos y materiales tiene cada custodio, por clave "tipo:id".
const summary = ref({})

// Origen y destino se manejan con una clave "tipo:id" porque el desplegable sólo
// guarda un valor y el custodio son dos datos (si es sucursal o persona, y cuál).
// El origen se elige al cargar (pickDefaultOrigin): antes abría siempre en
// «Bodega (sin sucursal)», que nunca tiene materiales, y parecía que la
// pantalla ya no los manejaba (Chaguaní, 2026-11-26).
const fromKey = ref(null)
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

const holderTotals = (key) => summary.value[key] ?? { devices: 0, materials: 0 }

// «43 equipos · 6 materiales»: en el desplegable se ve dónde está cada cosa.
const countsText = (key) => {
  const { devices: d, materials: m } = holderTotals(key)
  const parts = []
  if (d) parts.push(`${d} equipo${d === 1 ? '' : 's'}`)
  if (m) parts.push(`${m} material${m === 1 ? '' : 'es'}`)
  return parts.length ? parts.join(' · ') : 'sin existencias'
}

const holderOptions = computed(() => {
  const options = [{ key: 'branch:', label: 'Bodega (sin sucursal)', kind: `Sin ubicar · ${countsText('branch:')}` }]
  for (const b of branches.value) {
    const key = `branch:${b.id}`
    options.push({ key, label: b.name || `Sucursal #${b.id}`, kind: `Sucursal / bodega · ${countsText(key)}` })
  }
  for (const u of staff.value) {
    const key = `user:${u.id}`
    options.push({ key, label: u.name, kind: `Persona · ${countsText(key)}` })
  }
  return options
})

// Abre donde hay algo: la bodega con más existencias; si ninguna tiene, la
// persona con más; si nadie tiene nada, la primera bodega real.
const pickDefaultOrigin = () => {
  const total = (key) => holderTotals(key).devices + holderTotals(key).materials
  const fullest = (opts) => opts.filter(o => total(o.key) > 0).sort((a, b) => total(b.key) - total(a.key))[0]
  const opts = holderOptions.value

  return fullest(opts.filter(o => o.key.startsWith('branch:')))?.key
    ?? fullest(opts.filter(o => o.key.startsWith('user:')))?.key
    ?? opts.find(o => o.key.startsWith('branch:') && o.key !== 'branch:')?.key
    ?? 'branch:'
}

// Otros orígenes con materiales, para ofrecerlos cuando el elegido no tiene.
const otherMaterialHolders = computed(() => holderOptions.value
  .filter(o => o.key !== fromKey.value && holderTotals(o.key).materials > 0)
  .map(o => ({ key: o.key, label: o.label, materials: holderTotals(o.key).materials })))

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
const filterTerms = computed(() => searchTerms(filter.value))
const visibleDevices = computed(() => filterTerms.value.length
  ? devices.value.filter(d => matchesIndex(filterTerms.value, d._search))
  : devices.value)
const visibleMaterials = computed(() => filterTerms.value.length
  ? materials.value.filter(m => matchesIndex(filterTerms.value, m._search))
  : materials.value)

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

const loadSummary = async () => {
  try {
    const { data } = await inventoryApi.holdersSummary()
    summary.value = Object.fromEntries((data || []).map(h => [`${h.type}:${h.id ?? ''}`, h]))
  } catch {
    summary.value = {}   // sin conteos la pantalla funciona igual
  }
}

/**
 * Lo que tiene el origen. `keepSelection`: refrescar tras descontar sin perder
 * lo que el usuario ya iba eligiendo para la entrega (sólo se recorta a lo que
 * siga habiendo). Devuelve si pudo traer el dato.
 */
const loadHoldings = async ({ keepSelection = false } = {}) => {
  const holder = parseKey(fromKey.value)
  if (!holder) return false

  if (!keepSelection) {
    loading.value = true
    selectedDeviceIds.value = []
    materialQty.value = {}
    filter.value = ''
  }
  try {
    const { data } = await inventoryApi.holdings({
      holder_type: holder.type,
      holder_id: holder.id ?? undefined,
    })
    devices.value = (data.devices || []).map(d => ({ ...d, _search: searchIndex([d.item, d.serial, d.mac]) }))
    materials.value = (data.materials || []).map(m => ({ ...m, _search: searchIndex([m.item]) }))

    if (keepSelection) {
      const ids = new Set(devices.value.map(d => d.id))
      selectedDeviceIds.value = selectedDeviceIds.value.filter(id => ids.has(id))
      const left = Object.fromEntries(materials.value.map(m => [m.stock_id, Number(m.quantity)]))
      materialQty.value = Object.fromEntries(Object.entries(materialQty.value)
        .filter(([id]) => id in left)
        .map(([id, q]) => [id, Math.min(Number(q) || 0, left[id])]))
    }
    return true
  } catch (e) {
    toast.value?.error('Error', firstError(e, 'No se pudieron cargar las existencias.'))
    return false
  } finally {
    loading.value = false
  }
}

// Cambiar el origen (a mano, con «Invertir» o desde «Hay materiales en…») recarga lo que tiene.
watch(fromKey, (value, old) => { if (value && value !== old) loadHoldings() })

// ── Descontar material ───────────────────────────────────────────────────
// La única salida de un material era entregarlo o gastarlo en una orden: un
// ingreso digitado de más quedaba inflando el saldo para siempre. Un error de
// digitación queda como «Ajuste» (no es pérdida); lo demás, como «Baja».
const WRITE_OFF_REASONS = [
  { key: 'ingreso_de_mas', label: 'Se ingresó de más', hint: 'Error al digitar la cantidad. Queda como «Ajuste»: no cuenta como pérdida.' },
  { key: 'danado',         label: 'Dañado',                hint: 'Queda como «Baja» en Movimientos.' },
  { key: 'perdido',        label: 'Perdido o faltante',    hint: 'Queda como «Baja» en Movimientos.' },
  { key: 'devuelto',       label: 'Devuelto al proveedor', hint: 'Queda como «Baja» en Movimientos.' },
]

const writeOff = ref(null)
const writingOff = ref(false)
const writeOffError = ref('')

const openWriteOff = (m) => {
  writeOffError.value = ''
  writeOff.value = {
    stock_id: m.stock_id,
    item: m.item,
    unit: m.unit,
    decimals: m.decimals,
    available: Number(m.quantity),
    step: quantityStep(m),
    quantity: null,
    reason: null,   // sin preselección: el motivo decide si es ajuste o baja
    notes: '',
  }
}

const closeWriteOff = () => { if (!writingOff.value) writeOff.value = null }

const writeOffQuantityError = computed(() => {
  const w = writeOff.value
  if (!w) return ''
  const value = Number(w.quantity)
  if (!w.quantity || !Number.isFinite(value) || value <= 0) return 'Indica cuánto descontar (mayor que cero).'
  // Misma regla que el consumo en la orden: precisión del producto y tope de saldo.
  return quantityError({ decimals: w.decimals, unit: w.unit, quantity: w.available, source_label: fromLabel.value }, value)
})

const writeOffRemaining = computed(() => {
  const w = writeOff.value
  return w ? Math.max(0, w.available - (Number(w.quantity) || 0)) : 0
})

const canWriteOff = computed(() => !!writeOff.value?.reason && !writeOffQuantityError.value)

const writeOffButtonLabel = computed(() => {
  const w = writeOff.value
  const q = Number(w?.quantity) || 0
  return q > 0 ? `Descontar ${fmtQty(q)}${w.unit ? ` ${w.unit}` : ''}` : 'Descontar'
})

const submitWriteOff = async () => {
  const w = writeOff.value
  const holder = parseKey(fromKey.value)
  if (!w || !holder || holder.id === null || !canWriteOff.value || writingOff.value) return

  writingOff.value = true
  writeOffError.value = ''
  let done = false
  try {
    const { data } = await inventoryApi.writeOff({
      stock_id: w.stock_id,
      holder_type: holder.type,
      holder_id: holder.id,
      quantity: Number(w.quantity),
      reason: w.reason,
      notes: w.notes?.trim() || null,
    })
    const left = data?.remaining ?? writeOffRemaining.value
    toast.value?.success(
      data?.type === 'ajuste' ? 'Corrección registrada' : 'Baja registrada',
      `${w.item}: quedan ${fmtQty(left)}${w.unit ? ` ${w.unit}` : ''} en ${fromLabel.value}.`
    )
    for (const aviso of data?.warnings ?? []) toast.value?.info('Revisa el gasto', aviso)
    // El disponible cambia ya, sin esperar la recarga: abrir otro «Descontar…»
    // enseguida no debe ofrecer el saldo viejo.
    const row = materials.value.find(m => m.stock_id === w.stock_id)
    if (row) row.quantity = left
    writeOff.value = null
    done = true
  } catch (e) {
    if (!e?.response) {
      // Sin respuesta no se sabe si el descuento quedó grabado: repetirlo a
      // ciegas podría descontar dos veces. Se trae el saldo real primero.
      const [refreshed] = await Promise.all([loadHoldings({ keepSelection: true }), loadSummary()])
      if (refreshed) {
        const fresh = materials.value.find(m => m.stock_id === w.stock_id)
        if (writeOff.value) writeOff.value.available = fresh ? Number(fresh.quantity) : 0
        writeOffError.value = 'No hubo respuesta del servidor. El disponible de arriba ya está actualizado: '
          + 'revísalo antes de volver a intentar, por si el descuento sí quedó.'
      } else {
        writeOffError.value = 'No hay conexión con el servidor. Antes de volver a intentar, revisa en '
          + 'Movimientos si el descuento quedó registrado.'
      }
    } else {
      // El motivo se queda en la ventana, junto a lo que hay que corregir.
      writeOffError.value = firstError(e, 'No se pudo descontar el material.')
    }
  } finally {
    writingOff.value = false
  }
  // Recargar DESPUÉS de soltar `writingOff`: si se hacía dentro, el modal ya
  // cerrado dejaba el estado en «Descontando…» y el siguiente salía bloqueado.
  if (done) await Promise.all([loadHoldings({ keepSelection: true }), loadSummary()])
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
    await Promise.all([loadHoldings(), loadSummary()])
  } catch (e) {
    toast.value?.error('No se movió nada', firstError(e, 'No se pudo registrar la entrega.'))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadCatalogs(), loadSummary()])
  fromKey.value = pickDefaultOrigin()   // el watch carga sus existencias
})
</script>
