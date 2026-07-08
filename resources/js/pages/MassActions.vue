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

      <!-- ─── Failover de Facturación ───────────────────── -->
      <section class="mt-10">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-xl flex items-center justify-center shadow-md shadow-indigo-500/30">
            <icon-lucide-receipt class="w-5 h-5 text-white" />
          </div>
          <div class="flex-1">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Failover de Facturación</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">
              Clientes a quienes no se les pudo crear la factura mensual automáticamente.
              El sistema reintenta a las 2h, 6h y 24h. Tras 3 intentos quedan marcados como
              <strong>exhausted</strong> y requieren acción manual.
            </p>
          </div>
          <button
            @click="loadActionLogs"
            class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition"
            title="Recargar"
          >
            <icon-lucide-refresh-cw class="w-4 h-4 text-gray-600 dark:text-gray-400" :class="{ 'animate-spin': loadingLogs }" />
          </button>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Fallidos (reintentables)</div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ logStats.failed ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Exhausted</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ logStats.exhausted ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Recuperados</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ logStats.success ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Listos para retry</div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ logStats.ready_now ?? 0 }}</div>
          </div>
        </div>

        <!-- Filtros + acción masiva -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div class="p-4 flex flex-wrap items-end gap-3 border-b border-gray-100 dark:border-gray-700">
            <div>
              <label class="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Período</label>
              <input
                v-model="filterPeriod"
                type="month"
                class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200"
              />
            </div>
            <div>
              <label class="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
              <select
                v-model="filterStatus"
                class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200"
              >
                <option value="">Fallidos + Exhausted</option>
                <option value="failed">Solo fallidos</option>
                <option value="exhausted">Solo exhausted</option>
                <option value="success">Recuperados</option>
              </select>
            </div>
            <button
              @click="loadActionLogs"
              class="px-3 py-1.5 text-sm font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
            >
              Aplicar filtros
            </button>
            <div class="ml-auto">
              <button
                @click="confirmRetryAll = true"
                :disabled="(logStats.failed + logStats.exhausted) === 0 || retryAllRunning"
                class="px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-500/25 disabled:opacity-50 disabled:cursor-not-allowed transition"
              >
                <icon-lucide-refresh-cw class="w-4 h-4 inline -mt-0.5 mr-1" :class="{ 'animate-spin': retryAllRunning }" />
                Reintentar todos del período
              </button>
            </div>
          </div>

          <!-- Confirmación retry-all -->
          <div v-if="confirmRetryAll" class="px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-200 dark:border-indigo-800 flex items-center justify-between">
            <span class="text-sm text-indigo-800 dark:text-indigo-200">
              Se reintentarán {{ logStats.failed + logStats.exhausted }} factura(s) fallida(s)
              {{ filterPeriod ? `del período ${filterPeriod}` : 'de todos los períodos' }}. ¿Continuar?
            </span>
            <div class="flex gap-2">
              <button
                @click="runRetryAll"
                :disabled="retryAllRunning"
                class="px-3 py-1.5 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-50"
              >
                {{ retryAllRunning ? 'Procesando...' : 'Sí, reintentar' }}
              </button>
              <button
                @click="confirmRetryAll = false"
                :disabled="retryAllRunning"
                class="px-3 py-1.5 text-sm font-medium rounded-lg bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300"
              >
                Cancelar
              </button>
            </div>
          </div>

          <!-- Tabla -->
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr class="text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">
                  <th class="px-4 py-3">Cliente</th>
                  <th class="px-4 py-3">Router</th>
                  <th class="px-4 py-3">Período</th>
                  <th class="px-4 py-3">Intentos</th>
                  <th class="px-4 py-3">Estado</th>
                  <th class="px-4 py-3">Próximo retry</th>
                  <th class="px-4 py-3">Último error</th>
                  <th class="px-4 py-3 text-right">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr v-if="loadingLogs">
                  <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                    <icon-lucide-loader-2 class="w-5 h-5 animate-spin inline" />
                    Cargando...
                  </td>
                </tr>
                <tr v-else-if="actionLogs.length === 0">
                  <td colspan="8" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                    <icon-lucide-circle-check class="w-8 h-8 mx-auto mb-2 text-green-500" />
                    No hay facturas fallidas en el período seleccionado.
                  </td>
                </tr>
                <tr
                  v-for="log in actionLogs"
                  :key="log.id"
                  class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium text-gray-800 dark:text-gray-200">
                      {{ logCustomerName(log) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ log.customer?.email }}</div>
                  </td>
                  <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ log.router?.name ?? '—' }}</td>
                  <td class="px-4 py-3 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                    {{ formatPeriod(log.period_start) }}
                  </td>
                  <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-full text-xs font-mono text-gray-700 dark:text-gray-300">
                      {{ log.attempts }} / 3
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <span
                      :class="[
                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold',
                        statusBadgeClass(log.status)
                      ]"
                    >
                      {{ statusLabel(log.status) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                    {{ log.next_retry_at ? formatDateTime(log.next_retry_at) : '—' }}
                  </td>
                  <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-xs truncate" :title="log.last_error">
                    {{ log.last_error ?? '—' }}
                  </td>
                  <td class="px-4 py-3 text-right">
                    <button
                      v-if="log.status !== 'success'"
                      @click="retrySingle(log)"
                      :disabled="retryingId === log.id"
                      class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-50 transition"
                    >
                      <icon-lucide-loader-2 v-if="retryingId === log.id" class="w-3 h-3 animate-spin inline" />
                      <span v-else>Reintentar</span>
                    </button>
                    <router-link
                      v-else-if="log.invoice"
                      :to="{ name: 'InvoiceDetail', params: { id: log.invoice.id } }"
                      class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
                    >
                      Ver factura {{ log.invoice.number }}
                    </router-link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Paginación -->
          <div v-if="logsPagination.last_page > 1" class="px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500">
            <span>Página {{ logsPagination.current_page }} de {{ logsPagination.last_page }} — {{ logsPagination.total }} registro(s)</span>
            <Pagination :current-page="logsPagination.current_page" :total-pages="logsPagination.last_page" @change="changePage" />
          </div>
        </div>
      </section>

      <!-- ─── Failover de Cortes / Sincronización RB ─────── -->
      <section class="mt-10">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 bg-gradient-to-br from-rose-500 to-red-600 rounded-xl flex items-center justify-center shadow-md shadow-rose-500/30">
            <icon-lucide-scissors class="w-5 h-5 text-white" />
          </div>
          <div class="flex-1">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Failover de Cortes / Sincronización RB</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">
              Clientes <strong>suspendidos en la DB</strong> cuyo corte en el router falló o no está confirmado.
              El sistema reintenta con backoff (30m, 2h, 6h, 24h). Tras {{ suspMaxAttempts }} intentos quedan
              <strong>agotados</strong> y requieren acción manual. Usa <strong>Sincronizar ahora</strong> para
              re-cortar en la RB a los que están suspendidos en DB pero no en el router.
            </p>
          </div>
          <button
            @click="loadSuspensionLogs"
            class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition"
            title="Recargar"
          >
            <icon-lucide-refresh-cw class="w-4 h-4 text-gray-600 dark:text-gray-400" :class="{ 'animate-spin': loadingSuspLogs }" />
          </button>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Fallidos</div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ suspStats.failed ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Agotados (manual)</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{{ suspStats.needs_manual ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Pendientes</div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">{{ suspStats.pending ?? 0 }}</div>
          </div>
          <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">Listos para retry</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ suspStats.ready_now ?? 0 }}</div>
          </div>
        </div>

        <!-- Filtros + sincronizar -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div class="p-4 flex flex-wrap items-end gap-3 border-b border-gray-100 dark:border-gray-700">
            <div>
              <label class="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Estado</label>
              <select
                v-model="suspFilterStatus"
                class="px-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200"
              >
                <option value="">Fallidos + Pendientes</option>
                <option value="failed">Solo fallidos</option>
                <option value="pending">Solo pendientes</option>
                <option value="success">Recuperados</option>
              </select>
            </div>
            <button
              @click="loadSuspensionLogs"
              class="px-3 py-1.5 text-sm font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
            >
              Aplicar filtros
            </button>
            <div class="ml-auto">
              <button
                @click="runReconcile"
                :disabled="reconcileRunning"
                class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-500/25 disabled:opacity-50 disabled:cursor-not-allowed transition"
              >
                <icon-lucide-refresh-cw class="w-4 h-4 inline -mt-0.5 mr-1" :class="{ 'animate-spin': reconcileRunning }" />
                {{ reconcileRunning ? 'Sincronizando...' : 'Sincronizar ahora' }}
              </button>
            </div>
          </div>

          <!-- Tabla -->
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr class="text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">
                  <th class="px-4 py-3">Cliente</th>
                  <th class="px-4 py-3">Router</th>
                  <th class="px-4 py-3">IP</th>
                  <th class="px-4 py-3">Acción</th>
                  <th class="px-4 py-3">Intentos</th>
                  <th class="px-4 py-3">Estado</th>
                  <th class="px-4 py-3">Próximo retry</th>
                  <th class="px-4 py-3">Último error</th>
                  <th class="px-4 py-3 text-right">Acción</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr v-if="loadingSuspLogs">
                  <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                    <icon-lucide-loader-2 class="w-5 h-5 animate-spin inline" />
                    Cargando...
                  </td>
                </tr>
                <tr v-else-if="suspensionLogs.length === 0">
                  <td colspan="9" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                    <icon-lucide-circle-check class="w-8 h-8 mx-auto mb-2 text-green-500" />
                    No hay cortes pendientes ni fallidos. DB y routers están sincronizados.
                  </td>
                </tr>
                <tr
                  v-for="log in suspensionLogs"
                  :key="log.id"
                  class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium text-gray-800 dark:text-gray-200">
                      {{ logCustomerName(log) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ log.customer?.email }}</div>
                  </td>
                  <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ log.router?.name ?? '—' }}</td>
                  <td class="px-4 py-3 text-gray-700 dark:text-gray-300 font-mono text-xs">{{ log.ip ?? '—' }}</td>
                  <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ suspActionLabel(log.action) }}</td>
                  <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-full text-xs font-mono text-gray-700 dark:text-gray-300">
                      {{ log.attempts }} / {{ suspMaxAttempts }}
                    </span>
                  </td>
                  <td class="px-4 py-3">
                    <span
                      :class="[
                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold',
                        suspStatusBadge(log)
                      ]"
                    >
                      {{ suspStatusLabel(log) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                    {{ log.next_retry_at ? formatDateTime(log.next_retry_at) : '—' }}
                  </td>
                  <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 max-w-xs truncate" :title="log.error_message">
                    {{ log.error_message ?? '—' }}
                  </td>
                  <td class="px-4 py-3 text-right">
                    <button
                      v-if="log.status !== 'success'"
                      @click="retrySuspension(log)"
                      :disabled="suspRetryingId === log.id"
                      class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-50 transition"
                    >
                      <icon-lucide-loader-2 v-if="suspRetryingId === log.id" class="w-3 h-3 animate-spin inline" />
                      <span v-else>Reintentar</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Paginación -->
          <div v-if="suspPagination.last_page > 1" class="px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500">
            <span>Página {{ suspPagination.current_page }} de {{ suspPagination.last_page }} — {{ suspPagination.total }} registro(s)</span>
            <Pagination :current-page="suspPagination.current_page" :total-pages="suspPagination.last_page" @change="changeSuspPage" />
          </div>
        </div>
      </section>

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
import { apiClient } from '@/services/api'
import billingService from '@/services/billing'
import NotificationToast from '@/components/NotificationToast.vue'
import Pagination from '@/components/ui/Pagination.vue'

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

    const { data } = await apiClient.get('/routers')
    routers.value = (data || [])
      .filter(r => r.status === 'active')
      .map(r => ({
        ...r,
        cut_type_name: r.cut_type?.name ?? null,
      }))
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

// ─── Failover de Facturación ──────────────────────────────
const actionLogs       = ref([])
const logStats         = ref({ failed: 0, exhausted: 0, success: 0, ready_now: 0 })
const loadingLogs      = ref(false)
const retryingId       = ref(null)
const retryAllRunning  = ref(false)
const confirmRetryAll  = ref(false)
const filterPeriod     = ref(new Date().toISOString().slice(0, 7)) // YYYY-MM
const filterStatus     = ref('')
const logsPagination   = ref({ current_page: 1, last_page: 1, total: 0 })

const logCustomerName = (log) => {
  const cp = log.customer?.customer_profile
  if (cp) return [cp.name, cp.last_name].filter(Boolean).join(' ').trim() || log.customer?.user_name
  return log.customer?.user_name ?? `Cliente #${log.customer_id}`
}

const formatPeriod = (date) => {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('es', { month: 'long', year: 'numeric' })
}

const formatDateTime = (date) => {
  if (!date) return '—'
  return new Date(date).toLocaleString('es', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

const statusBadgeClass = (status) => ({
  failed:    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  exhausted: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  success:   'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
}[status] || 'bg-gray-100 text-gray-700')

const statusLabel = (status) => ({
  failed: 'Fallido',
  exhausted: 'Exhausted',
  success: 'Recuperado',
}[status] || status)

const loadActionLogs = async (page = 1) => {
  loadingLogs.value = true
  try {
    const params = { page }
    if (filterPeriod.value) params.period = filterPeriod.value
    if (filterStatus.value) params.status = filterStatus.value

    const [logsRes, statsRes] = await Promise.all([
      billingService.getActionLogs(params),
      billingService.getActionLogStats({ period: filterPeriod.value }),
    ])

    actionLogs.value = logsRes.data.data ?? []
    logsPagination.value = {
      current_page: logsRes.data.current_page,
      last_page:    logsRes.data.last_page,
      total:        logsRes.data.total,
    }
    logStats.value = statsRes.data
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudieron cargar los logs de facturación.')
  } finally {
    loadingLogs.value = false
  }
}

const changePage = (page) => {
  if (page < 1 || page > logsPagination.value.last_page) return
  loadActionLogs(page)
}

const retrySingle = async (log) => {
  retryingId.value = log.id
  try {
    const { data } = await billingService.retryActionLog(log.id)
    if (data.success) {
      toast.value?.success('Reintento exitoso', `Factura creada para ${logCustomerName(log)}.`)
    } else {
      toast.value?.error('Reintento fallido', data.log?.last_error ?? 'No se pudo crear la factura.')
    }
    await loadActionLogs(logsPagination.value.current_page)
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudo reintentar el log.')
  } finally {
    retryingId.value = null
  }
}

const runRetryAll = async () => {
  retryAllRunning.value = true
  try {
    const { data } = await billingService.retryAllActionLogs(filterPeriod.value)
    toast.value?.success(
      'Retry masivo completado',
      `${data.success} recuperadas · ${data.failed} aún fallan · ${data.processed} procesadas.`
    )
    confirmRetryAll.value = false
    await loadActionLogs(1)
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudo completar el retry masivo.')
  } finally {
    retryAllRunning.value = false
  }
}

// ─── Failover de Cortes / Sincronización RB ──────────────
const suspMaxAttempts   = 4
const suspensionLogs    = ref([])
const suspStats         = ref({ failed: 0, pending: 0, success: 0, needs_manual: 0, ready_now: 0 })
const loadingSuspLogs   = ref(false)
const suspRetryingId    = ref(null)
const reconcileRunning  = ref(false)
const suspFilterStatus  = ref('')
const suspPagination    = ref({ current_page: 1, last_page: 1, total: 0 })

const suspActionLabel = (action) => ({
  SUSPEND: 'Corte',
  UNSUSPEND: 'Reactivación',
  INSTALL_POLICY: 'Política',
}[action] || action)

// "Agotado" es derivado: falló y ya consumió todos los intentos.
const isSuspExhausted = (log) => log.status === 'failed' && log.attempts >= suspMaxAttempts

const suspStatusBadge = (log) => {
  if (isSuspExhausted(log)) return 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
  return {
    failed:  'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    pending: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
  }[log.status] || 'bg-gray-100 text-gray-700'
}

const suspStatusLabel = (log) => {
  if (isSuspExhausted(log)) return 'Agotado'
  return {
    failed: 'Fallido',
    pending: 'Pendiente',
    success: 'Recuperado',
  }[log.status] || log.status
}

const loadSuspensionLogs = async (page = 1) => {
  loadingSuspLogs.value = true
  try {
    const params = { page }
    if (suspFilterStatus.value) params.status = suspFilterStatus.value

    const [logsRes, statsRes] = await Promise.all([
      billingService.getSuspensionLogs(params),
      billingService.getSuspensionLogStats(),
    ])

    suspensionLogs.value = logsRes.data.data ?? []
    suspPagination.value = {
      current_page: logsRes.data.current_page,
      last_page:    logsRes.data.last_page,
      total:        logsRes.data.total,
    }
    suspStats.value = statsRes.data
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudieron cargar los logs de cortes.')
  } finally {
    loadingSuspLogs.value = false
  }
}

const changeSuspPage = (page) => {
  if (page < 1 || page > suspPagination.value.last_page) return
  loadSuspensionLogs(page)
}

const retrySuspension = async (log) => {
  suspRetryingId.value = log.id
  try {
    const { data } = await billingService.retrySuspensionLog(log.id)
    if (data.success) {
      toast.value?.success('Reintento exitoso', `Router sincronizado para ${logCustomerName(log)}.`)
    } else {
      toast.value?.error('Reintento fallido', data.log?.error_message ?? 'El router sigue sin responder.')
    }
    await loadSuspensionLogs(suspPagination.value.current_page)
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudo reintentar el corte.')
  } finally {
    suspRetryingId.value = null
  }
}

const runReconcile = async () => {
  reconcileRunning.value = true
  try {
    const { data } = await billingService.reconcileSuspensions()
    toast.value?.success(
      'Sincronización completada',
      `${data.reblocked_ok} re-cortados · ${data.already_confirmed} ya OK · ${data.reblocked_failed} con error · ${data.skipped_backoff} en espera.`
    )
    await loadSuspensionLogs(1)
  } catch (e) {
    console.error(e)
    toast.value?.error('Error', 'No se pudo ejecutar la sincronización.')
  } finally {
    reconcileRunning.value = false
  }
}

onMounted(() => {
  loadRouters()
  loadActionLogs()
  loadSuspensionLogs()
})
</script>
