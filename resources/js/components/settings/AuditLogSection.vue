<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-emerald-100 dark:bg-emerald-900/30 rounded-lg">
          <v-icon name="md-history" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Bitácora de auditoría</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400">
            Todo lo que mueve plata: precios de planes, cambios de plan de un
            cliente, pagos y configuración de facturación. Solo lectura.
          </p>
        </div>
      </div>
    </div>

    <!-- ══ FILTROS ══ -->
    <div class="p-4 md:p-6 border-b border-gray-200 dark:border-gray-700">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tipo de registro</label>
          <select v-model="filters.model_type" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            <option value="">Todos</option>
            <option v-for="m in options.models" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Acción</label>
          <select v-model="filters.action" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            <option value="">Todas</option>
            <option v-for="a in options.actions" :key="a" :value="a">{{ a }}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Origen</label>
          <select v-model="filters.source" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
            <option value="">Cualquiera</option>
            <option v-for="s in options.sources" :key="s" :value="s">{{ sourceLabel(s) }}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Desde</label>
          <input v-model="filters.from" type="date" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Hasta</label>
          <input v-model="filters.to" type="date" class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
        </div>
      </div>

      <div class="mt-3 flex flex-wrap items-center gap-2">
        <input
          v-model="filters.search"
          type="text"
          placeholder="Buscar en la descripción…"
          class="flex-1 min-w-[200px] text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
          @keyup.enter="load(1)"
        />
        <button
          type="button"
          class="text-sm bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg transition-all"
          @click="load(1)"
        >
          Filtrar
        </button>
        <button
          type="button"
          class="text-sm text-gray-600 dark:text-gray-400 underline"
          @click="reset"
        >
          Limpiar
        </button>
      </div>
    </div>

    <!-- ══ LISTADO ══ -->
    <div class="p-4 md:p-6">
      <div v-if="loading" class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        Cargando…
      </div>

      <div v-else-if="!rows.length" class="py-10 text-center">
        <v-icon name="md-history" class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto" />
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
          No hay movimientos registrados con esos filtros.
        </p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
              <th class="py-2 pr-4 font-medium whitespace-nowrap">Fecha</th>
              <th class="py-2 pr-4 font-medium whitespace-nowrap">Quién</th>
              <th class="py-2 pr-4 font-medium whitespace-nowrap">Origen</th>
              <th class="py-2 pr-4 font-medium">Qué cambió</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-for="row in rows" :key="row.id" class="align-top">
              <td class="py-3 pr-4 whitespace-nowrap text-gray-600 dark:text-gray-400">
                {{ formatDate(row.created_at) }}
              </td>
              <td class="py-3 pr-4 whitespace-nowrap text-gray-800 dark:text-gray-200">
                {{ actorName(row) }}
              </td>
              <td class="py-3 pr-4 whitespace-nowrap">
                <span :class="sourceClass(row.source)" class="text-xs px-2 py-0.5 rounded-full">
                  {{ sourceLabel(row.source) }}
                </span>
              </td>
              <td class="py-3 pr-4 text-gray-800 dark:text-gray-200">
                <p>{{ row.description || row.action }}</p>
                <button
                  v-if="row.old_values || row.new_values"
                  type="button"
                  class="mt-1 text-xs text-emerald-700 dark:text-emerald-400 underline"
                  @click="expanded === row.id ? expanded = null : expanded = row.id"
                >
                  {{ expanded === row.id ? 'Ocultar detalle' : 'Ver detalle' }}
                </button>
                <pre
                  v-if="expanded === row.id"
                  class="mt-2 text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-3 overflow-x-auto"
                >{{ detail(row) }}</pre>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ══ PAGINACIÓN ══ -->
      <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between">
        <p class="text-xs text-gray-500 dark:text-gray-400">
          Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} registros
        </p>
        <div class="flex gap-2">
          <button
            type="button"
            class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40"
            :disabled="meta.current_page <= 1"
            @click="load(meta.current_page - 1)"
          >
            Anterior
          </button>
          <button
            type="button"
            class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 disabled:opacity-40"
            :disabled="meta.current_page >= meta.last_page"
            @click="load(meta.current_page + 1)"
          >
            Siguiente
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { apiClient } from '@/services/api'

const loading  = ref(false)
const rows     = ref([])
const expanded = ref(null)
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

function sourceLabel (source) {
  return SOURCE_LABELS[source] || source || '—'
}

function sourceClass (source) {
  return {
    web: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    api: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
    console: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    import: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    scheduler: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
  }[source] || 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
}

function actorName (row) {
  if (!row.user) return 'Sistema'
  const nombre = [row.user.user_name, row.user.user_lastname].filter(Boolean).join(' ')
  return nombre || row.user.name || 'Sistema'
}

function formatDate (value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('es-CO', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
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
