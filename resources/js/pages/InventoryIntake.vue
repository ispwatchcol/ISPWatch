<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      <div class="max-w-4xl mx-auto">

        <!-- Header -->
        <div class="mb-6">
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="oi-diff-added" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Ingresar al inventario
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            Lo que entra a la empresa —una compra, equipos nuevos— se carga aquí, varios a la vez.
            Para pasar cosas entre bodegas y técnicos usa
            <RouterLink to="/inventory/transfers" class="underline">Entregas y traspasos</RouterLink>.
          </p>
        </div>

        <!-- ¿Qué entra? Dos tipos, una sola página. -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6" role="tablist" data-testid="intake-type">
          <button v-for="t in tipos" :key="t.key" type="button" role="tab" :aria-selected="tipo === t.key"
            @click="setTipo(t.key)"
            class="text-left rounded-xl border-2 p-4 transition"
            :class="tipo === t.key
              ? 'border-purple-600 bg-purple-50 dark:bg-purple-900/20'
              : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:border-purple-300'">
            <span class="flex items-center gap-2 font-semibold text-gray-800 dark:text-white">
              <v-icon :name="t.icon" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
              {{ t.title }}
            </span>
            <span class="block mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t.hint }}</span>
          </button>
        </div>

        <!-- ═══════════════ MATERIAL POR CANTIDAD ═══════════════ -->
        <section v-if="tipo === 'material'" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6" data-testid="intake-material">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
            <div>
              <label class="label">Entra a *</label>
              <SearchableSelect v-model="matDest" :items="destinationOptions" item-key="key" item-label="label"
                item-sublabel="kind" item-icon="md-storemalldirectory" placeholder="Bodega o persona…"
                search-placeholder="Buscar bodega o persona…" empty-text="No hay sucursales ni personal."
                :invalid="matTried && !matDest" data-testid="intake-material-dest" />
            </div>
            <div>
              <label class="label">Nota (opcional)</label>
              <input v-model="matNotes" type="text" maxlength="255" placeholder="Ej: factura 1234, compra de diciembre…" class="input" />
            </div>
          </div>

          <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase mb-2">Qué entra</p>
          <div class="space-y-2">
            <div v-for="(line, i) in matLines" :key="line.uid" data-testid="intake-material-line"
              class="grid grid-cols-[1fr_9.5rem_auto] gap-2 items-start">
              <div>
                <SearchableSelect v-model="line.stock_id" :items="materialOptions" :item-label="stockName"
                  :item-sublabel="materialSublabel" item-icon="oi-package" placeholder="Material…"
                  search-placeholder="Buscar material: cable, RJ45, amarres…" empty-text="No hay materiales por cantidad."
                  :invalid="!!lineError(line)" size="sm" />
                <p v-if="lineError(line)" class="mt-1 text-[11px] text-rose-600 dark:text-rose-400">{{ lineError(line) }}</p>
              </div>
              <div class="flex items-center gap-1">
                <input v-model.number="line.quantity" type="number" min="0" :step="stepOf(line.stock_id)" placeholder="Cant."
                  onwheel="this.blur()" data-testid="intake-material-qty"
                  class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500" />
                <span class="text-xs text-gray-500 dark:text-gray-400 w-14 shrink-0 truncate" :title="stockById(line.stock_id)?.unit || ''">{{ stockById(line.stock_id)?.unit || '' }}</span>
              </div>
              <button type="button" @click="removeMatLine(i)" :disabled="matLines.length === 1" title="Quitar línea"
                class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 disabled:opacity-30 disabled:hover:bg-transparent">
                <v-icon name="md-delete" class="w-4 h-4" />
              </button>
            </div>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
            <button type="button" @click="addMatLine" class="text-sm text-purple-700 dark:text-purple-300 hover:underline inline-flex items-center gap-1">
              <v-icon name="md-add" class="w-4 h-4" /> Otra línea
            </button>
            <button type="button" @click="newMaterialOpen = !newMaterialOpen" data-testid="intake-new-material"
              class="text-sm text-gray-600 dark:text-gray-300 hover:underline inline-flex items-center gap-1">
              <v-icon name="bi-plus-circle" class="w-4 h-4" /> ¿No está en la lista? Crear material
            </button>
          </div>

          <!-- Alta del material sin salir de aquí: antes eran dos pantallas
               (Stock → Agregar material y luego Entregas → Entrada). -->
          <div v-if="newMaterialOpen" class="mt-4 rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50/50 dark:bg-purple-900/10 p-4">
            <p class="text-sm font-medium text-gray-800 dark:text-white mb-3">Material nuevo (se cuenta por cantidad, sin serial)</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="label">Nombre *</label>
                <input v-model.trim="newMaterial.model" type="text" placeholder="Fibra drop, RJ45, amarres…" class="input" />
              </div>
              <div>
                <label class="label">Marca</label>
                <input v-model.trim="newMaterial.brand" type="text" placeholder="GENÉRICO" class="input" />
              </div>
              <div>
                <label class="label">Unidad</label>
                <select v-model="newMaterial.unit" @change="onNewUnit" class="input">
                  <option v-for="u in units" :key="u.name" :value="u.name">{{ u.name }}</option>
                </select>
              </div>
              <div>
                <label class="label">Precisión</label>
                <select v-model.number="newMaterial.quantity_decimals" class="input">
                  <option :value="0">Sólo enteros (piezas)</option>
                  <option :value="1">Hasta 1 decimal</option>
                  <option :value="2">Hasta 2 decimales (metros)</option>
                </select>
              </div>
              <div class="sm:col-span-2">
                <label class="label">Precio por {{ newMaterial.unit || 'unidad' }} (COP)</label>
                <input v-model.number="newMaterial.price" type="number" min="0" step="any" placeholder="0" class="input" />
                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">
                  Precio de catálogo por unidad de medida: se sugiere al cobrar lo consumido. Se puede cambiar luego en Catálogo.
                </p>
              </div>
            </div>
            <div class="mt-3 flex gap-2">
              <button type="button" @click="createMaterial" :disabled="!newMaterial.model || creatingProduct"
                class="bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg">
                {{ creatingProduct ? 'Creando…' : 'Crear y usar en el ingreso' }}
              </button>
              <button type="button" @click="newMaterialOpen = false" class="text-sm text-gray-500 dark:text-gray-400 px-3">Cancelar</button>
            </div>
          </div>

          <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center gap-3">
            <button type="button" @click="submitMaterials" :disabled="saving" data-testid="intake-material-submit"
              class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 disabled:opacity-50 text-white px-6 py-3 rounded-xl font-medium shadow-lg">
              {{ saving ? 'Registrando…' : `Registrar ingreso${filledMatLines.length > 1 ? ` (${filledMatLines.length} líneas)` : ''}` }}
            </button>
            <p class="text-xs text-gray-500 dark:text-gray-400">
              Se registra todo o nada: si una línea tiene un error, no entra ninguna.
            </p>
          </div>
        </section>

        <!-- ═══════════════ EQUIPOS CON SERIAL ═══════════════ -->
        <section v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6" data-testid="intake-devices">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
            <div>
              <label class="label">Modelo *</label>
              <SearchableSelect v-model="devStock" :items="serialStocks" :item-label="stockName" data-testid="intake-device-model"
                :item-sublabel="(s) => `Precio: ${formatCurrency(s.price)}`" item-icon="md-inventory-round"
                placeholder="Modelo del equipo…" search-placeholder="Buscar por marca o modelo…"
                empty-text="No hay modelos por serial." :invalid="devTried && !devStock" />
              <button type="button" @click="newModelOpen = !newModelOpen" data-testid="intake-new-model"
                class="mt-1 text-xs text-gray-600 dark:text-gray-300 hover:underline">¿No está? Crear modelo</button>
            </div>
            <div>
              <label class="label">Queda en</label>
              <SearchableSelect v-model="devDest" :items="destinationOptions" item-key="key" item-label="label"
                item-sublabel="kind" item-icon="md-storemalldirectory" placeholder="Bodega (sin sucursal)"
                search-placeholder="Buscar bodega o persona…" empty-text="No hay sucursales ni personal."
                clearable clear-label="Bodega (sin sucursal)" :clear-value="null" data-testid="intake-device-dest" />
            </div>
            <div>
              <label class="label">Proveedor</label>
              <SearchableSelect v-model="devProvider" :items="providers" item-icon="bi-building"
                placeholder="Sin proveedor" search-placeholder="Buscar proveedor…" empty-text="No hay proveedores."
                clearable clear-label="Sin proveedor" :clear-value="null" />
            </div>
          </div>

          <div v-if="newModelOpen" class="mb-5 rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50/50 dark:bg-purple-900/10 p-4">
            <p class="text-sm font-medium text-gray-800 dark:text-white mb-3">Modelo nuevo (cada unidad lleva serial y MAC)</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="label">Marca *</label>
                <input v-model.trim="newModel.brand" type="text" placeholder="Huawei, MikroTik…" class="input" />
              </div>
              <div>
                <label class="label">Modelo *</label>
                <input v-model.trim="newModel.model" type="text" placeholder="HG8145V5, hAP ac²…" class="input" />
              </div>
              <div>
                <label class="label">Precio por unidad (COP)</label>
                <input v-model.number="newModel.price" type="number" min="0" step="any" placeholder="0" class="input" />
              </div>
            </div>
            <div class="mt-3 flex gap-2">
              <button type="button" @click="createModel" :disabled="!newModel.brand || !newModel.model || creatingProduct"
                class="bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg">
                {{ creatingProduct ? 'Creando…' : 'Crear y usar' }}
              </button>
              <button type="button" @click="newModelOpen = false" class="text-sm text-gray-500 dark:text-gray-400 px-3">Cancelar</button>
            </div>
          </div>

          <div class="flex items-baseline justify-between gap-3 mb-2">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Unidades ({{ filledDevRows.length }})</p>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">
              Con lector de código de barras: Enter en el serial salta a la MAC, y en la MAC abre la fila siguiente.
            </p>
          </div>
          <div class="space-y-2">
            <div v-for="(row, i) in devRows" :key="row.uid" data-testid="intake-device-row">
              <div class="grid grid-cols-[1.5rem_1fr_1fr_auto] gap-2 items-center">
                <span class="text-xs text-gray-400 text-right">{{ i + 1 }}</span>
                <input :ref="el => setRowRef(el, i, 'serial')" v-model="row.serial" type="text" placeholder="Serial"
                  @input="row.error = ''" @keydown.enter.prevent="focusRow(i, 'mac')"
                  class="px-3 py-2 text-sm font-mono rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500"
                  :class="rowProblem(row) ? 'border-rose-400' : 'border-gray-300 dark:border-gray-600'" />
                <input :ref="el => setRowRef(el, i, 'mac')" :value="row.mac" @input="onMacInput(row, $event)" type="text"
                  placeholder="MAC (opcional)" @keydown.enter.prevent="nextRow(i)"
                  class="px-3 py-2 text-sm font-mono rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500"
                  :class="rowProblem(row) ? 'border-rose-400' : 'border-gray-300 dark:border-gray-600'" />
                <button type="button" @click="removeDevRow(i)" :disabled="devRows.length === 1" title="Quitar fila"
                  class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 disabled:opacity-30 disabled:hover:bg-transparent">
                  <v-icon name="md-delete" class="w-4 h-4" />
                </button>
              </div>
              <p v-if="rowProblem(row)" class="ml-8 mt-1 text-[11px] text-rose-600 dark:text-rose-400">{{ rowProblem(row) }}</p>
            </div>
          </div>
          <button type="button" @click="nextRow(devRows.length - 1)" class="mt-3 text-sm text-purple-700 dark:text-purple-300 hover:underline inline-flex items-center gap-1">
            <v-icon name="md-add" class="w-4 h-4" /> Otra unidad
          </button>

          <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center gap-3">
            <button type="button" @click="submitDevices" :disabled="saving" data-testid="intake-device-submit"
              class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 disabled:opacity-50 text-white px-6 py-3 rounded-xl font-medium shadow-lg">
              {{ saving ? `Registrando ${progress}…` : `Registrar ${filledDevRows.length || ''} equipo${filledDevRows.length === 1 ? '' : 's'}` }}
            </button>
            <p class="text-xs text-gray-500 dark:text-gray-400">
              Cada equipo se registra aparte: si uno falla (serial repetido), los demás entran y el que falló queda marcado aquí.
            </p>
          </div>
        </section>

        <!-- Lo último que entró: confirma que el ingreso quedó, sin ir a Movimientos. -->
        <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6">
          <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-base font-bold text-gray-800 dark:text-white">Últimos ingresos</h2>
            <RouterLink to="/inventory/movements" class="text-xs text-purple-700 dark:text-purple-300 underline">Ver todos los movimientos</RouterLink>
          </div>
          <p v-if="!recent.length" class="text-sm text-gray-500 dark:text-gray-400">Todavía no hay ingresos registrados.</p>
          <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
            <li v-for="m in recent" :key="m.id" class="py-2 flex items-start justify-between gap-3 text-sm">
              <span class="min-w-0">
                <span class="text-gray-800 dark:text-white">
                  <span v-if="!m.serial" class="font-semibold">{{ fmtQty(m.quantity) }}{{ m.unit ? ` ${m.unit}` : '' }} · </span>{{ m.item }}
                </span>
                <span v-if="m.serial" class="block text-[11px] font-mono text-gray-500 dark:text-gray-400">S/N {{ m.serial }}</span>
              </span>
              <span class="shrink-0 text-right text-xs text-gray-500 dark:text-gray-400">
                {{ m.to }}<br>{{ fmtDate(m.created_at) }}
              </span>
            </li>
          </ul>
        </section>
      </div>

      <NotificationToast ref="toast" />
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import inventoryApi from '@/services/api/inventory'
import inventoryStockApi from '@/services/api/inventory-stock'
import inventoryUnitApi from '@/services/api/inventory-unit'
import inventoryBranchApi from '@/services/api/inventory-branch'
import inventoryProviderApi from '@/services/api/inventory-provider'
import catalogsApi from '@/services/api/catalogs'
import { firstError } from '@/utils/apiError'
import { fmtQty, isLengthUnit, materialDecimals, quantityStep } from '@/utils/materialQuantity'
import NotificationToast from '@/components/NotificationToast.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

/**
 * Ingresar al inventario: el único sitio por donde ENTRA mercancía.
 *
 * Antes eran tres entradas de menú y dos pantallas por material: «Agregar
 * equipo con serial» (un equipo por formulario), «Agregar material» (crea el
 * producto SIN existencia) y, en otra página, «Entregas y traspasos → Entrada de
 * material» (le da la existencia). Aquí:
 *
 * - Material: varias líneas a un mismo destino en UNA llamada a
 *   POST /inventory/transfers sin origen (= entrada). El backend la aplica en
 *   una transacción: o entran todas las líneas o ninguna.
 * - Equipos: varias unidades del mismo modelo, una llamada a POST /inventory
 *   por unidad (el alta individual es la que valida serial/MAC únicos y
 *   escribe el primer movimiento del kardex). Las que fallan se quedan en
 *   pantalla con su motivo; las demás entran.
 * - Si el material o el modelo no existe, se crea aquí mismo (POST
 *   /inventory-stock) y queda elegido.
 *
 * No hay endpoint nuevo: son las mismas llamadas que hacían las pantallas
 * anteriores.
 */

const route = useRoute()
const router = useRouter()
const toast = ref(null)
const saving = ref(false)
const creatingProduct = ref(false)

const tipos = [
  { key: 'material', title: 'Material por cantidad', icon: 'oi-package', hint: 'Cable, fibra, conectores, amarres: se cuentan por metros o piezas.' },
  { key: 'equipo', title: 'Equipos con serial', icon: 'bi-upc', hint: 'ONU, routers, antenas: cada unidad con su serial y su MAC.' },
]
const tipo = ref(route.query.tipo === 'equipo' ? 'equipo' : 'material')
const setTipo = (key) => {
  tipo.value = key
  router.replace({ query: { ...route.query, tipo: key, material: undefined } })
}

// ── Catálogos ───────────────────────────────────────────────────────────
const stocks = ref([])
// Las básicas desde el inicio (son las mismas en todas las empresas); el
// catálogo del servidor las reemplaza y suma las propias al llegar.
const units = ref(['unidad', 'metro', 'rollo', 'caja', 'paquete'].map(name => ({ name, decimals: name === 'metro' ? 2 : 0 })))
const branches = ref([])
const staff = ref([])
const providers = ref([])
const recent = ref([])

const stockById = (id) => stocks.value.find(s => s.id === id) ?? null
const stockName = (s) => `${s.brand ?? ''} ${s.model ?? ''}`.trim() || `Producto #${s.id}`
const materialOptions = computed(() => stocks.value.filter(s => s.is_serialized === false))
const serialStocks = computed(() => stocks.value.filter(s => s.is_serialized !== false))
const materialSublabel = (s) => {
  const unit = s.unit || 'unidad'
  return s.available !== undefined ? `En existencia: ${fmtQty(s.available)} ${unit}` : `Por ${unit}`
}

// Custodios con la misma clave «tipo:id» que usan Entregas y traspasos y Movimientos.
const destinationOptions = computed(() => [
  ...branches.value.map(b => ({ key: `branch:${b.id}`, label: b.name || `Sucursal #${b.id}`, kind: 'Sucursal / bodega' })),
  ...staff.value.map(u => ({ key: `user:${u.id}`, label: u.name, kind: 'Persona' })),
])
const parseKey = (key) => {
  if (!key) return null
  const [type, id] = key.split(':')
  return { type, id: Number(id) }
}

const loadStocks = async () => {
  try {
    const { data } = await inventoryStockApi.getAll()
    stocks.value = data || []
  } catch (e) {
    toast.value?.error('Error', firstError(e, 'No se pudo cargar el catálogo.'))
  }
}

const loadRecent = async () => {
  try {
    const { data } = await inventoryApi.movements({ type: 'entrada', per_page: 8 })
    recent.value = data?.data ?? []
  } catch {
    recent.value = []
  }
}

// Cada catálogo se pinta en cuanto llega, sin esperar a los demás: con una
// conexión lenta, «Crear material» no debe abrir con la unidad en blanco
// porque todavía faltaba la lista de proveedores.
const loadCatalogs = () => Promise.allSettled([
  inventoryUnitApi.getAll().then(({ data }) => { if (Array.isArray(data) && data.length) units.value = data }),
  inventoryBranchApi.getAll().then(({ data }) => { branches.value = data || [] }),
  catalogsApi.getUsers({ staff: 1 }).then(({ data }) => { staff.value = data || [] }),
  inventoryProviderApi.getAll().then(({ data }) => { providers.value = data || [] }),
])

// ── Material por cantidad ───────────────────────────────────────────────
let uidSeq = 0
const newMatLine = (stockId = null) => ({ uid: ++uidSeq, stock_id: stockId, quantity: null })

const matDest = ref(null)
const matNotes = ref('')
const matLines = ref([newMatLine()])
const matTried = ref(false)

const addMatLine = () => matLines.value.push(newMatLine())
const removeMatLine = (i) => { if (matLines.value.length > 1) matLines.value.splice(i, 1) }

const stepOf = (stockId) => {
  const s = stockById(stockId)
  return quantityStep(s ? { decimals: s.quantity_decimals } : null)
}

const isBlank = (line) => !line.stock_id && (line.quantity === null || line.quantity === '')
const filledMatLines = computed(() => matLines.value.filter(l => !isBlank(l)))

const lineError = (line) => {
  if (isBlank(line)) return ''
  const s = stockById(line.stock_id)
  if (!s) return matTried.value ? 'Elige el material.' : ''
  const value = Number(line.quantity)
  if (line.quantity === null || line.quantity === '' || !Number.isFinite(value) || value <= 0) {
    return matTried.value ? 'Indica cuánto entra (mayor que cero).' : ''
  }
  const d = materialDecimals({ decimals: s.quantity_decimals })
  const factor = 10 ** d
  if (Math.abs(Math.round(value * factor) - value * factor) > 1e-6) {
    return d === 0 ? `Se registra en cantidades enteras${s.unit ? ` (${s.unit})` : ''}.` : `Admite hasta ${d} decimal(es).`
  }
  if (filledMatLines.value.filter(l => l.stock_id === line.stock_id).length > 1) {
    return 'Este material está repetido: súmalo en una sola línea.'
  }
  return ''
}

// Material nuevo, creado sin salir de la página.
const newMaterialOpen = ref(false)
const emptyMaterial = () => ({ brand: '', model: '', unit: 'metro', quantity_decimals: 2, price: null })
const newMaterial = ref(emptyMaterial())
const onNewUnit = () => {
  const unit = units.value.find(u => u.name === newMaterial.value.unit)
  if (unit && unit.decimals !== null && unit.decimals !== undefined) newMaterial.value.quantity_decimals = Number(unit.decimals) || 0
  else if (isLengthUnit(newMaterial.value.unit)) newMaterial.value.quantity_decimals = 2
}

const createProduct = async (payload) => {
  creatingProduct.value = true
  try {
    const { data } = await inventoryStockApi.create(payload)
    await loadStocks()
    return data?.id ?? null
  } catch (e) {
    toast.value?.error('Error', firstError(e, 'No se pudo crear el producto.'))
    return null
  } finally {
    creatingProduct.value = false
  }
}

const createMaterial = async () => {
  const id = await createProduct({
    brand: newMaterial.value.brand || 'GENÉRICO',
    model: newMaterial.value.model,
    price: newMaterial.value.price || 0,
    is_serialized: false,
    unit: newMaterial.value.unit || 'unidad',
    quantity_decimals: Number(newMaterial.value.quantity_decimals) || 0,
  })
  if (!id) return
  const empty = matLines.value.find(l => !l.stock_id)
  if (empty) empty.stock_id = id
  else matLines.value.push(newMatLine(id))
  newMaterial.value = emptyMaterial()
  newMaterialOpen.value = false
  toast.value?.success('Material creado', 'Ya está elegido en el ingreso: escribe la cantidad.')
}

const submitMaterials = async () => {
  matTried.value = true
  const lines = filledMatLines.value
  if (!matDest.value) {
    toast.value?.error('Falta el destino', 'Elige a qué bodega o persona entra el material.')
    return
  }
  if (!lines.length) {
    toast.value?.error('Nada que registrar', 'Elige al menos un material y su cantidad.')
    return
  }
  if (lines.some(l => lineError(l))) {
    toast.value?.error('Revisa las líneas', 'Hay líneas marcadas en rojo.')
    return
  }

  const to = parseKey(matDest.value)
  saving.value = true
  try {
    // Sin source_* el backend lo registra como ENTRADA desde el proveedor.
    await inventoryApi.transfer({
      to_type: to.type,
      to_id: to.id,
      materials: lines.map(l => ({ stock_id: l.stock_id, quantity: Number(l.quantity) })),
      notes: matNotes.value || 'Entrada de material',
    })
    toast.value?.success('Ingreso registrado', lines.length === 1 ? 'El material ya está disponible.' : `${lines.length} materiales ya están disponibles.`)
    matLines.value = [newMatLine()]
    matNotes.value = ''
    matTried.value = false
    await Promise.all([loadStocks(), loadRecent()])
  } catch (e) {
    toast.value?.error('No se registró nada', firstError(e, 'No se pudo registrar el ingreso.'))
  } finally {
    saving.value = false
  }
}

// ── Equipos con serial ──────────────────────────────────────────────────
const newDevRow = () => ({ uid: ++uidSeq, serial: '', mac: '', error: '' })

const devStock = ref(null)
const devDest = ref(null)
const devProvider = ref(null)
const devRows = ref([newDevRow()])
const devTried = ref(false)
const progress = ref('')

const rowRefs = {}
const setRowRef = (el, i, field) => { if (el) rowRefs[`${i}:${field}`] = el }
const focusRow = async (i, field) => {
  await nextTick()
  rowRefs[`${i}:${field}`]?.focus()
}
const nextRow = (i) => {
  if (i >= devRows.value.length - 1) devRows.value.push(newDevRow())
  focusRow(i + 1, 'serial')
}
const removeDevRow = (i) => { if (devRows.value.length > 1) devRows.value.splice(i, 1) }

// Mismo formato que la ficha del equipo: AA:BB:CC:DD:EE:FF.
const onMacInput = (row, event) => {
  const hex = event.target.value.replace(/[^0-9A-Fa-f]/g, '').substring(0, 12)
  row.mac = (hex.match(/.{1,2}/g)?.join(':') || hex).toUpperCase()
  event.target.value = row.mac
  row.error = ''
}

const norm = (v) => String(v ?? '').trim().toLowerCase()
const isBlankRow = (row) => !norm(row.serial) && !norm(row.mac)
const filledDevRows = computed(() => devRows.value.filter(r => !isBlankRow(r)))

// Repetidos DENTRO de lo que se está cargando; contra lo ya registrado decide el servidor.
const duplicateProblem = (row) => {
  if (isBlankRow(row)) return ''
  const serial = norm(row.serial)
  const mac = norm(row.mac)
  const filled = filledDevRows.value
  if (serial && filled.filter(r => norm(r.serial) === serial).length > 1) return 'Serial repetido en esta carga.'
  if (mac && filled.filter(r => norm(r.mac) === mac).length > 1) return 'MAC repetida en esta carga.'
  return ''
}
// Lo que se pinta bajo la fila: el rechazo del servidor del último intento o el repetido.
const rowProblem = (row) => row.error || duplicateProblem(row)

const newModelOpen = ref(false)
const newModel = ref({ brand: '', model: '', price: null })

const createModel = async () => {
  const id = await createProduct({
    brand: newModel.value.brand,
    model: newModel.value.model,
    price: newModel.value.price || 0,
    is_serialized: true,
  })
  if (!id) return
  devStock.value = id
  newModel.value = { brand: '', model: '', price: null }
  newModelOpen.value = false
  toast.value?.success('Modelo creado', 'Ya está elegido: carga los seriales.')
}

const submitDevices = async () => {
  devTried.value = true
  if (!devStock.value) {
    toast.value?.error('Falta el modelo', 'Elige el modelo de los equipos.')
    return
  }
  const rows = filledDevRows.value
  if (!rows.length) {
    toast.value?.error('Nada que registrar', 'Escribe al menos un serial o una MAC.')
    return
  }
  if (rows.some(r => duplicateProblem(r))) {
    toast.value?.error('Revisa las filas', 'Hay seriales o MAC repetidos en la carga.')
    return
  }
  // Reintento: el motivo del intento anterior se descarta y lo decide el servidor otra vez.
  rows.forEach(r => { r.error = '' })

  // Un custodio: persona → user_id; sucursal → branch_id; nada → bodega sin ubicar.
  const holder = parseKey(devDest.value)
  const base = {
    stock_id: devStock.value,
    provider_id: devProvider.value || null,
    branch_id: holder?.type === 'branch' ? holder.id : null,
    user_id: holder?.type === 'user' ? holder.id : null,
  }

  saving.value = true
  let ok = 0
  const failed = []
  try {
    for (const [n, row] of rows.entries()) {
      progress.value = `${n + 1}/${rows.length}`
      try {
        await inventoryApi.create({ ...base, serial: row.serial.trim() || null, mac: row.mac.trim() || null })
        ok++
        row.done = true
      } catch (e) {
        row.error = firstError(e, 'No se pudo registrar.')
        failed.push(row)
      }
    }
  } finally {
    saving.value = false
    progress.value = ''
  }

  // Lo registrado sale de la lista; lo que falló se queda con su motivo.
  devRows.value = devRows.value.filter(r => !r.done && !isBlankRow(r))
  if (!devRows.value.length) devRows.value = [newDevRow()]

  if (ok) toast.value?.success('Equipos registrados', `${ok} equipo(s) ingresados.`)
  if (failed.length) toast.value?.error('Algunos no entraron', `${failed.length} fila(s) con error: revisa el motivo bajo cada una.`)
  devTried.value = false
  if (ok) await Promise.all([loadStocks(), loadRecent()])
}

// ── Utilidades ──────────────────────────────────────────────────────────
const formatCurrency = (value) => new Intl.NumberFormat('es-CO', {
  style: 'currency', currency: 'COP', minimumFractionDigits: 0,
}).format(value || 0)

const fmtDate = (value) => (value
  ? new Date(value).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  : '—')

onMounted(async () => {
  await Promise.all([loadStocks(), loadCatalogs(), loadRecent()])

  // Con una sola sucursal no hay nada que elegir.
  if (branches.value.length === 1) {
    matDest.value = `branch:${branches.value[0].id}`
    devDest.value = matDest.value
  }

  // ?material=<id>: viene de Catálogo → «Registrar entrada» con el material elegido.
  const preset = Number(route.query.material)
  if (preset && materialOptions.value.some(s => s.id === preset)) {
    tipo.value = 'material'
    matLines.value = [newMatLine(preset)]
  }
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1;
}
.input {
  @apply w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm
         focus:ring-2 focus:ring-purple-500 focus:border-transparent outline-none
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
</style>
