<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <NotificationToast ref="toast" />

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">

      <!-- ─── Header ─────────────────────────────────────── -->
      <div class="mb-10">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-11 h-11 bg-gradient-to-br from-red-500 to-rose-600 rounded-2xl flex items-center justify-center shadow-lg shadow-red-500/30">
            <icon-lucide-scissors class="w-5 h-5 text-white" />
          </div>
          <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Acciones Masivas</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Ejecuta cortes de servicio de forma manual e inmediata</p>
          </div>
        </div>

        <!-- Aviso de peligro -->
        <div class="mt-5 flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-4">
          <icon-lucide-triangle-alert class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
          <div>
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Esta acción es inmediata e irreversible</p>
            <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">
              Los cortes aquí ejecutados <strong>ignoran</strong> la fecha y hora configurada en cada router.
              Los clientes serán suspendidos en MikroTik de forma inmediata si superan el umbral de facturas vencidas.
            </p>
          </div>
        </div>
      </div>

      <!-- ─── Grid de tarjetas ───────────────────────────── -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- ── Tarjeta: Corte Global ────────────────────── -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
          <!-- Barra superior de color -->
          <div class="h-1.5 bg-gradient-to-r from-red-500 to-rose-500"></div>

          <div class="p-6">
            <!-- Icono + título -->
            <div class="flex items-start justify-between mb-4">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center">
                  <icon-lucide-globe class="w-5 h-5 text-red-600 dark:text-red-400" />
                </div>
                <div>
                  <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Corte Global</h2>
                  <p class="text-xs text-gray-500 dark:text-gray-400">Todos los routers activos</p>
                </div>
              </div>
              <span class="px-2.5 py-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-xs font-semibold rounded-full">
                {{ routers.length }} routers
              </span>
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-400 mb-5">
              Recorre todos los routers del sistema y suspende los clientes que tengan un número de
              facturas vencidas igual o mayor al umbral definido en la configuración de cada router.
            </p>

            <!-- Confirmación inline -->
            <div v-if="!globalConfirm" class="flex justify-end">
              <button
                @click="globalConfirm = true"
                :disabled="globalRunning || perRouterRunning"
                class="flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 active:scale-95
                       text-white text-sm font-semibold rounded-xl shadow-md shadow-red-500/25
                       disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-150"
              >
                <icon-lucide-scissors class="w-4 h-4" />
                Ejecutar Corte Global
              </button>
            </div>

            <div v-else class="rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 space-y-3">
              <p class="text-sm font-medium text-red-800 dark:text-red-300">¿Confirmas el corte global?</p>
              <div class="flex gap-3">
                <button
                  @click="runGlobalCut"
                  :disabled="globalRunning"
                  class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5
                         bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl
                         disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                >
                  <icon-lucide-loader-2 v-if="globalRunning" class="w-4 h-4 animate-spin" />
                  <icon-lucide-check v-else class="w-4 h-4" />
                  {{ globalRunning ? 'Ejecutando...' : 'Sí, ejecutar' }}
                </button>
                <button
                  @click="globalConfirm = false"
                  :disabled="globalRunning"
                  class="px-4 py-2.5 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                         text-gray-700 dark:text-gray-300 text-sm font-medium rounded-xl hover:bg-gray-50
                         dark:hover:bg-gray-600 transition-all"
                >
                  Cancelar
                </button>
              </div>
            </div>

            <!-- Resultado global -->
            <div v-if="globalResult" class="mt-4">
              <ResultCard :result="globalResult" :timestamp="globalTimestamp" />
            </div>
          </div>
        </div>

        <!-- ── Tarjeta: Corte por Router ───────────────── -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div class="h-1.5 bg-gradient-to-r from-orange-500 to-amber-500"></div>

          <div class="p-6">
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900/30 rounded-xl flex items-center justify-center">
                <icon-lucide-server class="w-5 h-5 text-orange-600 dark:text-orange-400" />
              </div>
              <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Corte por Router</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">Selecciona un router específico</p>
              </div>
            </div>

            <!-- Selector de router -->
            <div class="mb-4">
              <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-2">
                Router objetivo
              </label>
              <div v-if="loadingRouters" class="flex items-center gap-2 text-sm text-gray-500 py-3">
                <icon-lucide-loader-2 class="w-4 h-4 animate-spin" />
                Cargando routers...
              </div>
              <div v-else class="grid gap-2 max-h-48 overflow-y-auto pr-1">
                <label
                  v-for="router in routers"
                  :key="router.id"
                  :class="[
                    'flex items-center gap-3 p-3 rounded-xl border cursor-pointer transition-all duration-150',
                    selectedRouter?.id === router.id
                      ? 'border-orange-400 bg-orange-50 dark:bg-orange-900/20 dark:border-orange-700'
                      : 'border-gray-200 dark:border-gray-700 hover:border-orange-300 dark:hover:border-orange-700'
                  ]"
                >
                  <input type="radio" :value="router" v-model="selectedRouter" class="accent-orange-500" />
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100 truncate">{{ router.name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ router.ip }}</p>
                  </div>
                  <!-- badge corte config -->
                  <span
                    v-if="router.cut_type_name"
                    :class="[
                      'px-2 py-0.5 text-[10px] font-semibold rounded-full shrink-0',
                      router.cut_type_name === 'Corte Automático'
                        ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                        : router.cut_type_name === 'Sin Corte'
                          ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'
                          : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'
                    ]"
                  >
                    {{ router.cut_type_name }}
                  </span>
                </label>
                <p v-if="routers.length === 0" class="text-sm text-gray-500 text-center py-4">
                  No hay routers activos disponibles.
                </p>
              </div>
            </div>

            <!-- Confirmación por router -->
            <div v-if="!routerConfirm">
              <button
                @click="routerConfirm = true"
                :disabled="!selectedRouter || perRouterRunning || globalRunning"
                class="w-full flex items-center justify-center gap-2 px-5 py-2.5
                       bg-orange-600 hover:bg-orange-700 active:scale-95
                       text-white text-sm font-semibold rounded-xl shadow-md shadow-orange-500/25
                       disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-150"
              >
                <icon-lucide-scissors class="w-4 h-4" />
                Cortar {{ selectedRouter ? `"${selectedRouter.name}"` : 'este router' }}
              </button>
            </div>

            <div v-else class="rounded-xl bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 p-4 space-y-3">
              <p class="text-sm font-medium text-orange-800 dark:text-orange-300">
                ¿Confirmas el corte de <strong>{{ selectedRouter?.name }}</strong>?
              </p>
              <div class="flex gap-3">
                <button
                  @click="runRouterCut"
                  :disabled="perRouterRunning"
                  class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5
                         bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold rounded-xl
                         disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                >
                  <icon-lucide-loader-2 v-if="perRouterRunning" class="w-4 h-4 animate-spin" />
                  <icon-lucide-check v-else class="w-4 h-4" />
                  {{ perRouterRunning ? 'Ejecutando...' : 'Sí, cortar' }}
                </button>
                <button
                  @click="routerConfirm = false"
                  :disabled="perRouterRunning"
                  class="px-4 py-2.5 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                         text-gray-700 dark:text-gray-300 text-sm font-medium rounded-xl hover:bg-gray-50
                         dark:hover:bg-gray-600 transition-all"
                >
                  Cancelar
                </button>
              </div>
            </div>

            <!-- Resultado por router -->
            <div v-if="routerResult" class="mt-4">
              <ResultCard :result="routerResult" :timestamp="routerTimestamp" :label="lastCutRouter" />
            </div>
          </div>
        </div>

      </div>

      <!-- ─── Historial de esta sesión ──────────────────── -->
      <div v-if="history.length > 0" class="mt-8">
        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
          Historial de esta sesión
        </h3>
        <div class="space-y-2">
          <div
            v-for="(entry, i) in history"
            :key="i"
            class="flex items-center justify-between bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                   rounded-xl px-4 py-3 text-sm"
          >
            <div class="flex items-center gap-3">
              <span :class="entry.scope === 'Global' ? 'text-red-500' : 'text-orange-500'">
                <icon-lucide-scissors class="w-4 h-4" />
              </span>
              <span class="font-medium text-gray-800 dark:text-gray-200">{{ entry.scope }}</span>
              <span class="text-gray-400 dark:text-gray-500 text-xs">{{ entry.timestamp }}</span>
            </div>
            <div class="flex items-center gap-4 text-xs">
              <span class="text-red-600 dark:text-red-400 font-semibold">{{ entry.suspended }} suspendidos</span>
              <span class="text-yellow-600 dark:text-yellow-400">{{ entry.manual_pending }} manuales</span>
              <span v-if="entry.errors > 0" class="text-gray-500">{{ entry.errors }} errores</span>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import { apiClient } from '@/services/api'
import NotificationToast from '@/components/NotificationToast.vue'

// ─── Componente local de resultado ───────────────────────
const ResultCard = {
  props: ['result', 'timestamp', 'label'],
  template: `
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      <div class="px-4 py-2 bg-gray-50 dark:bg-gray-700/50 flex items-center justify-between">
        <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">
          {{ label ? label + ' — ' : '' }}Resultado
        </span>
        <span class="text-xs text-gray-400 dark:text-gray-500">{{ timestamp }}</span>
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-y sm:divide-y-0 divide-gray-100 dark:divide-gray-700">
        <div class="px-4 py-3 text-center">
          <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ result.suspended }}</div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Suspendidos</div>
        </div>
        <div class="px-4 py-3 text-center">
          <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ result.manual_pending }}</div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Pend. Manual</div>
        </div>
        <div class="px-4 py-3 text-center">
          <div class="text-2xl font-bold text-gray-600 dark:text-gray-300">{{ result.routers_processed }}</div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Routers</div>
        </div>
        <div class="px-4 py-3 text-center">
          <div class="text-2xl font-bold" :class="result.errors > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400'">
            {{ result.errors }}
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Errores</div>
        </div>
      </div>
    </div>
  `
}

// ─── Estado ───────────────────────────────────────────────
const toast         = ref(null)
const routers       = ref([])
const loadingRouters = ref(true)
const selectedRouter = ref(null)

// Global cut
const globalConfirm   = ref(false)
const globalRunning   = ref(false)
const globalResult    = ref(null)
const globalTimestamp = ref('')

// Per-router cut
const routerConfirm   = ref(false)
const perRouterRunning = ref(false)
const routerResult    = ref(null)
const routerTimestamp = ref('')
const lastCutRouter   = ref('')

// Historial sesión
const history = ref([])

// ─── Data ─────────────────────────────────────────────────
const loadRouters = async () => {
  loadingRouters.value = true
  try {
    const userData = JSON.parse(
      localStorage.getItem('userData') || sessionStorage.getItem('userData') || '{}'
    )
    if (!userData?.tenant_id) return

    const { data, error } = await supabase
      .from('router')
      .select(`
        id, name, ip, status,
        cut_type:cut_type_id(name)
      `)
      .eq('tenant_id', userData.tenant_id)
      .eq('status', 'active')

    if (!error) {
      routers.value = (data || []).map(r => ({
        ...r,
        cut_type_name: r.cut_type?.name ?? null,
      }))
    }
  } catch (e) {
    console.error('Error cargando routers:', e)
  } finally {
    loadingRouters.value = false
  }
}

// ─── Acciones ─────────────────────────────────────────────
const nowStr = () => new Date().toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', second: '2-digit' })

const runGlobalCut = async () => {
  globalRunning.value = true
  try {
    const { data } = await apiClient.post('/billing/run-auto-cut', {})
    globalResult.value   = data.stats
    globalTimestamp.value = nowStr()
    globalConfirm.value  = false
    history.value.unshift({
      scope: 'Global',
      timestamp: globalTimestamp.value,
      ...data.stats,
    })
    toast.value?.success('Corte global completado', `${data.stats.suspended} cliente(s) suspendidos.`)
  } catch (err) {
    console.error(err)
    toast.value?.error('Error', 'No se pudo ejecutar el corte global.')
  } finally {
    globalRunning.value = false
  }
}

const runRouterCut = async () => {
  if (!selectedRouter.value) return
  perRouterRunning.value = true
  lastCutRouter.value = selectedRouter.value.name
  try {
    const { data } = await apiClient.post('/billing/run-auto-cut', { router_id: selectedRouter.value.id })
    routerResult.value    = data.stats
    routerTimestamp.value = nowStr()
    routerConfirm.value   = false
    history.value.unshift({
      scope: selectedRouter.value.name,
      timestamp: routerTimestamp.value,
      ...data.stats,
    })
    toast.value?.success('Corte completado', `${data.stats.suspended} cliente(s) suspendidos en ${lastCutRouter.value}.`)
  } catch (err) {
    console.error(err)
    toast.value?.error('Error', 'No se pudo ejecutar el corte.')
  } finally {
    perRouterRunning.value = false
  }
}

onMounted(loadRouters)
</script>
