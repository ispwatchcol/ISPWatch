<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
    <!-- ══ CABECERA ══ -->
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700/70">
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3 min-w-0">
          <div class="p-2 bg-emerald-100 dark:bg-emerald-500/15 rounded-lg shrink-0">
            <v-icon name="md-history" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
          </div>
          <div class="min-w-0">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Bitácora de auditoría</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
              Todo lo que mueve plata: precios de planes, cambios de plan de un
              cliente, pagos y configuración de facturación. Solo lectura.
            </p>
          </div>
        </div>

        <button
          type="button"
          class="btn-ghost shrink-0"
          :disabled="loading"
          title="Actualizar"
          @click="load(meta.current_page)"
        >
          <v-icon name="md-refresh" class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          <span class="hidden sm:inline">Actualizar</span>
        </button>
      </div>
    </div>

    <!-- ══ FILTROS ══ -->
    <!-- La búsqueda y el botón de filtros quedan siempre a la vista; el resto
         se despliega. Lo que está aplicado se ve en las fichas de abajo, así
         que plegar el panel no esconde el estado. -->
    <div class="px-4 md:px-6 py-4 border-b border-gray-200 dark:border-gray-700/70 bg-gray-50/70 dark:bg-gray-900/30">
      <div class="flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[220px]">
          <v-icon
            name="md-search"
            class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none"
          />
          <input
            v-model="filters.search"
            type="text"
            placeholder="Buscar en la descripción…"
            class="field field-search"
            @keyup.enter="load(1)"
            @input="debouncedSearch"
          />
          <button
            v-if="filters.search"
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded-md
                   text-gray-400 hover:text-gray-600 dark:hover:text-gray-300
                   hover:bg-gray-100 dark:hover:bg-gray-700"
            title="Borrar búsqueda"
            @click="clearFilter('search')"
          >
            <v-icon name="md-close" class="w-3.5 h-3.5" />
          </button>
        </div>

        <button
          type="button"
          class="btn-ghost"
          :class="showFilters ? 'ring-2 ring-emerald-500/40 border-emerald-500' : ''"
          @click="showFilters = !showFilters"
        >
          <v-icon name="md-filterlist" class="w-4 h-4" />
          Filtros
          <span v-if="advancedCount" class="count-badge">{{ advancedCount }}</span>
          <v-icon
            name="md-keyboardarrowdown"
            class="w-4 h-4 transition-transform"
            :class="showFilters ? 'rotate-180' : ''"
          />
        </button>

        <button type="button" class="btn-primary" @click="load(1)">
          <v-icon name="md-search" class="w-4 h-4" />
          Filtrar
        </button>

        <button
          v-if="activeFilters.length"
          type="button"
          class="btn-ghost"
          @click="reset"
        >
          <v-icon name="md-close" class="w-4 h-4" />
          Limpiar
        </button>
      </div>

      <!-- Panel desplegable -->
      <div v-if="showFilters" class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div>
          <label class="field-label">Tipo de registro</label>
          <select v-model="filters.model_type" class="field" @change="load(1)">
            <option value="">Todos</option>
            <option v-for="m in options.models" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <div>
          <label class="field-label">Acción</label>
          <select v-model="filters.action" class="field" @change="load(1)">
            <option value="">Todas</option>
            <option v-for="a in options.actions" :key="a" :value="a">{{ a }}</option>
          </select>
        </div>

        <div>
          <label class="field-label">Origen</label>
          <select v-model="filters.source" class="field" @change="load(1)">
            <option value="">Cualquiera</option>
            <option v-for="s in options.sources" :key="s" :value="s">{{ sourceLabel(s) }}</option>
          </select>
        </div>

        <div>
          <label class="field-label">Desde</label>
          <input v-model="filters.from" type="date" class="field" @change="load(1)" />
        </div>

        <div>
          <label class="field-label">Hasta</label>
          <input v-model="filters.to" type="date" class="field" @change="load(1)" />
        </div>
      </div>

      <!-- Fichas de lo aplicado: se quitan una por una sin abrir el panel -->
      <div v-if="activeFilters.length" class="mt-3 flex flex-wrap items-center gap-1.5">
        <span class="text-xs text-gray-500 dark:text-gray-400">Filtrando por:</span>
        <button
          v-for="f in activeFilters"
          :key="f.key"
          type="button"
          class="filter-chip"
          :title="`Quitar «${f.label}»`"
          @click="clearFilter(f.key)"
        >
          <span class="text-gray-500 dark:text-gray-400">{{ f.name }}:</span>
          <span class="font-medium">{{ f.label }}</span>
          <v-icon name="md-close" class="w-3 h-3 opacity-60" />
        </button>
      </div>
    </div>

    <!-- ══ LISTADO ══ -->
    <div class="p-4 md:p-6">
      <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        Cargando…
      </div>

      <div v-else-if="!rows.length" class="py-12 text-center">
        <v-icon name="md-history" class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto" />
        <p class="mt-2 text-sm font-medium text-gray-600 dark:text-gray-300">
          No hay movimientos registrados con esos filtros.
        </p>
        <button
          v-if="activeFilters.length"
          type="button"
          class="mt-3 text-xs font-medium text-emerald-700 dark:text-emerald-400 hover:underline"
          @click="reset"
        >
          Quitar los filtros
        </button>
      </div>

      <div v-else>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
          {{ meta.total }} {{ meta.total === 1 ? 'movimiento' : 'movimientos' }}
        </p>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700/70">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
              <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <th class="th">Fecha</th>
                <th class="th">Quién</th>
                <th class="th">Origen</th>
                <th class="th w-full">Qué cambió</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
              <tr
                v-for="row in rows"
                :key="row.id"
                class="align-top transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
              >
                <td class="td whitespace-nowrap">
                  <span class="block text-gray-800 dark:text-gray-200">{{ formatDay(row.created_at) }}</span>
                  <span class="block text-xs text-gray-400 dark:text-gray-500">{{ formatTime(row.created_at) }}</span>
                </td>
                <td class="td whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <span class="avatar">{{ initials(actorName(row)) }}</span>
                    <span class="text-gray-800 dark:text-gray-200">{{ actorName(row) }}</span>
                  </div>
                </td>
                <td class="td whitespace-nowrap">
                  <span class="chip" :class="sourceClass(row.source)">{{ sourceLabel(row.source) }}</span>
                </td>
                <td class="td text-gray-800 dark:text-gray-200">
                  <p class="leading-snug">{{ row.description || row.action }}</p>
                  <button
                    v-if="row.old_values || row.new_values"
                    type="button"
                    class="mt-1 inline-flex items-center gap-1 text-xs font-medium
                           text-emerald-700 dark:text-emerald-400 hover:underline"
                    @click="expanded === row.id ? expanded = null : expanded = row.id"
                  >
                    <v-icon
                      name="md-chevronright"
                      class="w-3.5 h-3.5 transition-transform"
                      :class="expanded === row.id ? 'rotate-90' : ''"
                    />
                    {{ expanded === row.id ? 'Ocultar detalle' : 'Ver detalle' }}
                  </button>

                  <!-- Antes / después en columnas: el JSON crudo obligaba a
                       comparar a ojo dos bloques de texto. -->
                  <div
                    v-if="expanded === row.id"
                    class="mt-2 rounded-lg border border-gray-200 dark:border-gray-700/70
                           bg-gray-50 dark:bg-gray-900/50 overflow-x-auto"
                  >
                    <table v-if="diffRows(row).length" class="w-full text-xs">
                      <thead class="text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-gray-700/70">
                          <th class="text-left px-3 py-1.5 font-medium">Campo</th>
                          <th class="text-left px-3 py-1.5 font-medium">Antes</th>
                          <th class="text-left px-3 py-1.5 font-medium">Después</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-gray-100 dark:divide-gray-700/40">
                        <tr v-for="d in diffRows(row)" :key="d.key">
                          <td class="px-3 py-1.5 font-mono text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            {{ d.key }}
                          </td>
                          <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400 break-all">{{ d.before }}</td>
                          <td class="px-3 py-1.5 text-gray-800 dark:text-gray-100 break-all">{{ d.after }}</td>
                        </tr>
                      </tbody>
                    </table>
                    <pre v-else class="text-xs p-3 overflow-x-auto text-gray-600 dark:text-gray-300">{{ detail(row) }}</pre>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══ PAGINACIÓN ══ -->
      <div v-if="meta.last_page > 1" class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-gray-500 dark:text-gray-400">
          Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} registros
        </p>
        <div class="flex gap-2">
          <button
            type="button"
            class="btn-ghost"
            :disabled="meta.current_page <= 1 || loading"
            @click="load(meta.current_page - 1)"
          >
            <v-icon name="md-chevronleft-round" class="w-4 h-4" />
            Anterior
          </button>
          <button
            type="button"
            class="btn-ghost"
            :disabled="meta.current_page >= meta.last_page || loading"
            @click="load(meta.current_page + 1)"
          >
            Siguiente
            <v-icon name="md-chevronright" class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { apiClient } from '@/services/api'

const loading  = ref(false)
const rows     = ref([])
const expanded = ref(null)
const showFilters = ref(false)
const meta     = reactive({ current_page: 1, last_page: 1, total: 0 })
const options  = reactive({ models: [], actions: [], sources: [] })

const filters = reactive({
  model_type: '',
  action: '',
  source: '',
  from: '',
  to: '',
  search: '',
})

// El origen es lo que distingue "lo cambió un operador" de "lo cambió una
// carga masiva", que fue justo la ambigüedad del episodio del precio mal subido.
const SOURCE_LABELS = {
  web: 'Panel',
  api: 'API',
  console: 'Consola',
  import: 'Carga masiva',
  scheduler: 'Automático',
}

const FILTER_NAMES = {
  model_type: 'Tipo',
  action: 'Acción',
  source: 'Origen',
  from: 'Desde',
  to: 'Hasta',
  search: 'Texto',
}

function sourceLabel (source) {
  return SOURCE_LABELS[source] || source || '—'
}

function sourceClass (source) {
  return {
    web: 'chip-blue',
    api: 'chip-purple',
    console: 'chip-gray',
    import: 'chip-amber',
    scheduler: 'chip-emerald',
  }[source] || 'chip-gray'
}

function modelLabel (value) {
  return options.models.find(m => m.value === value)?.label || value
}

/** Lo aplicado, listo para pintar como fichas removibles una por una. */
const activeFilters = computed(() =>
  Object.entries(filters)
    .filter(([, value]) => !!value)
    .map(([key, value]) => ({
      key,
      name: FILTER_NAMES[key] || key,
      label: key === 'model_type' ? modelLabel(value)
        : key === 'source' ? sourceLabel(value)
        : value,
    }))
)

/** Sólo los del panel plegable: el buscador ya se ve solo. */
const advancedCount = computed(() => activeFilters.value.filter(f => f.key !== 'search').length)

function initials (name) {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map(part => part[0])
    .join('')
    .toUpperCase()
}

function actorName (row) {
  if (!row.user) return 'Sistema'
  const nombre = [row.user.user_name, row.user.user_lastname].filter(Boolean).join(' ')
  return nombre || row.user.name || 'Sistema'
}

function formatDay (value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('es-CO', {
    year: 'numeric', month: '2-digit', day: '2-digit',
  })
}

function formatTime (value) {
  if (!value) return ''
  return new Date(value).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })
}

function formatValue (value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'sí' : 'no'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

/**
 * Une las claves de antes y después para poder mostrarlas lado a lado. Si el
 * registro no trae objetos (por ejemplo, un valor suelto), la vista cae al
 * JSON crudo de `detail()`.
 */
function diffRows (row) {
  const before = isPlainObject(row.old_values) ? row.old_values : null
  const after  = isPlainObject(row.new_values) ? row.new_values : null
  if (!before && !after) return []

  const keys = [...new Set([...Object.keys(before || {}), ...Object.keys(after || {})])]
  return keys.map(key => ({
    key,
    before: formatValue(before?.[key]),
    after: formatValue(after?.[key]),
  }))
}

function isPlainObject (value) {
  return !!value && typeof value === 'object' && !Array.isArray(value)
}

function detail (row) {
  return JSON.stringify({ antes: row.old_values, despues: row.new_values }, null, 2)
}

async function load (page = 1) {
  loading.value = true
  expanded.value = null

  try {
    const params = { page }
    Object.entries(filters).forEach(([key, value]) => {
      if (value) params[key] = value
    })

    const { data } = await apiClient.get('/audit-logs', { params })

    rows.value = data.data || []
    meta.current_page = data.current_page || 1
    meta.last_page = data.last_page || 1
    meta.total = data.total || 0
  } catch (e) {
    rows.value = []
  } finally {
    loading.value = false
  }
}

// La búsqueda dispara sola tras la pausa de tecleo: pedir Enter cuando los
// demás filtros se aplican al vuelo era la incoherencia que más confundía.
let searchTimer = null
function debouncedSearch () {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(1), 400)
}
onBeforeUnmount(() => clearTimeout(searchTimer))

function clearFilter (key) {
  filters[key] = ''
  load(1)
}

function reset () {
  Object.keys(filters).forEach((key) => { filters[key] = '' })
  load(1)
}

onMounted(async () => {
  try {
    const { data } = await apiClient.get('/audit-logs/filters')
    options.models = data.models || []
    options.actions = data.actions || []
    options.sources = data.sources || []
  } catch (e) {
    // Sin catálogo los filtros quedan en "todos"; el listado igual funciona.
  }

  load(1)
})
</script>

<!--
  Los campos usaban `border-gray-300` sin `border` ni relleno, así que el
  navegador los pintaba con su estilo nativo: marco fino gris y texto pegado al
  borde, que sobre la tarjeta oscura se veía como una cuadrícula suelta.
-->
<style scoped>
.field {
  @apply w-full px-3 py-2 rounded-lg text-sm
         border border-gray-300 dark:border-gray-600
         bg-white dark:bg-gray-900/60 text-gray-900 dark:text-gray-100
         focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500
         transition-colors placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
/* Va después de `.field` a propósito: misma especificidad, gana la última. */
.field-search {
  @apply pl-9 pr-8;
}
.field-label {
  @apply block text-[11px] font-medium uppercase tracking-wide
         text-gray-500 dark:text-gray-400 mb-1;
}

/* Botones */
.btn-primary {
  @apply inline-flex items-center justify-center gap-1.5 text-sm font-medium
         bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg
         transition-colors disabled:opacity-50 disabled:cursor-not-allowed;
}
.btn-ghost {
  @apply inline-flex items-center justify-center gap-1.5 text-sm font-medium
         px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600
         text-gray-700 dark:text-gray-300
         hover:bg-gray-100 dark:hover:bg-gray-700/60
         transition-colors disabled:opacity-40 disabled:cursor-not-allowed;
}
.count-badge {
  @apply inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full
         text-[11px] font-semibold
         bg-emerald-600 text-white dark:bg-emerald-500;
}
.filter-chip {
  @apply inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs
         border border-gray-300 dark:border-gray-600
         bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200
         hover:border-red-400 hover:text-red-600 dark:hover:border-red-500/60 dark:hover:text-red-400
         transition-colors;
}

/* Tabla */
.th {
  @apply px-4 py-2.5 font-medium whitespace-nowrap;
}
.td {
  @apply px-4 py-3;
}
.avatar {
  @apply w-6 h-6 shrink-0 rounded-full grid place-items-center text-[10px] font-semibold
         bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300;
}

/* Etiquetas de origen */
.chip {
  @apply inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap;
}
.chip-blue {
  @apply bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300;
}
.chip-purple {
  @apply bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300;
}
.chip-amber {
  @apply bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300;
}
.chip-emerald {
  @apply bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300;
}
.chip-gray {
  @apply bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300;
}
</style>
