<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 p-4 sm:p-6">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />

    <!-- Contenido principal -->
    <!-- Encabezado -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-8 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <v-icon name="bi-hdd-rack" class="text-blue-600 w-6 h-6 sm:w-7 sm:h-7" />
              Routers del Sistema
            </h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
              Gestión de routers y configuración por zonas.
            </p>
        </div>
        <!-- Botón Agregar Router -->
          <button
            v-if="can('manage_routers')"
            @click="goToAddRouter"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center justify-center gap-2 transition-all w-full sm:w-auto"
          >
            <icon-lucide-plus class="w-4 h-4" />
            Agregar Router
          </button>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-4 sm:p-6">
        <!-- Filtros -->
        <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between mb-6 gap-4">
          <!-- Lado Izquierdo: Búsqueda y Limpiar -->
          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full md:w-auto flex-1">
            <input
              v-model="search"
              type="text"
              placeholder="Buscar por nombre, IP o usuario..."
              class="border border-gray-300 dark:border-gray-700 rounded-xl px-4 py-2.5 sm:py-2 w-full md:max-w-md focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white"
            />
            <button
              @click="clearSearch"
              class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-4 py-2.5 sm:py-2 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-all text-center"
            >
              Limpiar
            </button>
          </div>

          <!-- Lado Derecho: Acciones -->
            <div class="flex flex-wrap items-center gap-2 w-full md:w-auto justify-stretch sm:justify-end">
               <!-- Export CSV -->
            <button
              @click="exportToCSV"
              class="flex-1 sm:flex-none text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2.5 sm:py-2 rounded-xl hover:bg-blue-100 transition-all flex items-center justify-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="w-4 h-4" />
              CSV
            </button>

             <!-- Export Excel -->
            <button
              @click="exportToExcel"
              class="flex-1 sm:flex-none text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2.5 sm:py-2 rounded-xl hover:bg-green-100 transition-all flex items-center justify-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="w-4 h-4" />
              Excel
            </button>

              <button
                @click="openBlockRulesInfo"
                class="w-full sm:w-auto text-sm bg-orange-600 hover:bg-orange-700 text-white px-4 py-2.5 sm:py-2 rounded-xl flex items-center justify-center gap-2 transition-all shadow-md mt-2 sm:mt-0"
              >
                <icon-lucide-shield-ban class="w-4 h-4" />
                Configurar Reglas de Bloqueo
              </button>
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="loading" class="text-center py-12">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
          <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando routers...</p>
        </div>

        <!-- Tabla / Cards -->
        <div v-if="!loading" class="border border-gray-100 dark:border-gray-700 rounded-xl overflow-hidden">
          
          <!-- Desktop Table -->
          <div class="hidden md:block overflow-x-auto">
          <table class="min-w-full border-collapse">
            <thead>
              <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm uppercase tracking-wide">
                <th class="py-3 px-4 text-left">Nombre</th>
                <th class="py-3 px-4 text-left">IP</th>
                <th class="py-3 px-4 text-left">Usuario RB</th>
                <th class="py-3 px-4 text-left">Interfaz LAN</th>
                <th class="py-3 px-4 text-left">Versión Firmware</th>
                <th class="py-3 px-4 text-left">Estado</th>
                <th class="py-3 px-4 text-left">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="router in filteredRouters"
                :key="router.id"
                class="border-b border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-700/40 transition-all bg-white dark:bg-gray-800"
              >
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">
                  <div class="flex items-center gap-2">
                    <span>{{ router.name }}</span>
                    <span
                      v-if="router.falla_general"
                      title="Router marcado en falla general"
                      class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-100 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                    >
                      <icon-lucide-alert-triangle class="w-3 h-3" />
                      Falla general
                    </span>
                  </div>
                </td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.ip }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.user_rb }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ router.lan_interface || '—' }}</td>
                <td class="py-3 px-4 text-gray-600 dark:text-gray-300">{{ getScriptName(router.firmware_version) }}</td>
                <td class="py-3 px-4">
                  <span
                    class="inline-block px-3 py-1 text-xs font-semibold rounded-full"
                    :class="router.status === 'active'
                      ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                      : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                  >
                    {{ router.status || '—' }}
                  </span>
                </td>
                <td class="py-3 px-4 flex gap-2 flex-wrap">
                  <!-- Botón Editar -->
                  <button
                    v-if="can('manage_routers')"
                    @click="$router.push({ name: 'RouterEdit', params: { id: router.id } })"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-blue-50 text-blue-700 border border-blue-200
                          hover:bg-blue-100 hover:scale-[1.03] transition-all
                          dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                  >
                    <icon-lucide-pencil class="w-4 h-4" />
                    Editar
                  </button>

                  <!-- Botón Configurar WAN -->
                  <button
                    @click="openWanModal(router)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-purple-50 text-purple-700 border border-purple-200
                          hover:bg-purple-100 hover:scale-[1.03] transition-all
                          dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800 dark:hover:bg-purple-800/50"
                  >
                    <icon-lucide-network class="w-4 h-4" />
                    WAN
                  </button>

                  <!-- Botón Corte Rápido -->
                  <button
                    @click="openAutoCutModal(router)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-red-50 text-red-700 border border-red-200
                          hover:bg-red-100 hover:scale-[1.03] transition-all
                          dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                    title="Ejecutar corte automático ahora para este router"
                  >
                    <icon-lucide-scissors class="w-4 h-4" />
                    Corte
                  </button>

                  <!-- Botón Detalles -->
                  <button
                    @click="openDetailsModal(router)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-cyan-50 text-cyan-700 border border-cyan-200
                          hover:bg-cyan-100 hover:scale-[1.03] transition-all
                          dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800 dark:hover:bg-cyan-800/50"
                  >
                    <icon-lucide-bar-chart-3 class="w-4 h-4" />
                    Detalles
                  </button>

                  <!-- Botón Eliminar -->
                  <button
                    v-if="can('manage_routers')"
                    @click="deleteRouter(router.id)"
                    class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                          bg-red-50 text-red-700 border border-red-200
                          hover:bg-red-100 hover:scale-[1.03] transition-all
                          dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                  >
                    <icon-lucide-trash class="w-4 h-4" />
                    Eliminar
                  </button>
                </td>
              </tr>
              <tr v-if="filteredRouters.length === 0">
                <td colspan="7" class="text-center py-6 text-gray-500 dark:text-gray-400">
                  No se encontraron routers.
                </td>
              </tr>
            </tbody>
          </table>
          </div>

          <!-- Mobile Cards -->
          <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
            <div v-for="router in filteredRouters" :key="router.id" class="p-4 bg-white dark:bg-gray-800">
              <div class="flex justify-between items-start mb-3">
                <div class="flex items-center gap-2">
                  <h3 class="font-semibold text-gray-800 dark:text-gray-100 text-sm">{{ router.name }}</h3>
                  <span
                    v-if="router.falla_general"
                    class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-100 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300"
                  >
                    <icon-lucide-alert-triangle class="w-3 h-3" /> Falla general
                  </span>
                </div>
                <span
                  class="px-2.5 py-1 text-xs font-semibold rounded-full"
                  :class="router.status === 'active'
                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                    : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                >
                  {{ router.status || '—' }}
                </span>
              </div>
              
              <div class="grid grid-cols-2 gap-2 text-xs mb-4">
                <div><span class="text-gray-500 dark:text-gray-400">IP:</span> <span class="text-gray-800 dark:text-gray-200 ml-1">{{ router.ip }}</span></div>
                <div><span class="text-gray-500 dark:text-gray-400">Usuario:</span> <span class="text-gray-800 dark:text-gray-200 ml-1">{{ router.user_rb }}</span></div>
                <div><span class="text-gray-500 dark:text-gray-400">LAN:</span> <span class="text-gray-800 dark:text-gray-200 ml-1">{{ router.lan_interface || '—' }}</span></div>
                <div><span class="text-gray-500 dark:text-gray-400">Firmware:</span> <span class="text-gray-800 dark:text-gray-200 ml-1">{{ getScriptName(router.firmware_version) }}</span></div>
              </div>

              <div class="flex flex-wrap gap-2">
                <button
                  v-if="can('manage_routers')"
                  @click="$router.push({ name: 'RouterEdit', params: { id: router.id } })"
                  class="flex-1 min-w-[80px] px-2 py-2 text-xs font-medium rounded-lg flex justify-center items-center gap-1 bg-blue-50 text-blue-700 border border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
                >
                  <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                </button>
                <button
                  @click="openWanModal(router)"
                  class="flex-1 min-w-[80px] px-2 py-2 text-xs font-medium rounded-lg flex justify-center items-center gap-1 bg-purple-50 text-purple-700 border border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800"
                >
                  <icon-lucide-network class="w-3.5 h-3.5" /> WAN
                </button>
                <button
                  @click="openAutoCutModal(router)"
                  class="flex-1 min-w-[80px] px-2 py-2 text-xs font-medium rounded-lg flex justify-center items-center gap-1 bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                >
                  <icon-lucide-scissors class="w-3.5 h-3.5" /> Corte
                </button>
                <button
                  @click="openDetailsModal(router)"
                  class="flex-1 min-w-[80px] px-2 py-2 text-xs font-medium rounded-lg flex justify-center items-center gap-1 bg-cyan-50 text-cyan-700 border border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800"
                >
                  <icon-lucide-bar-chart-3 class="w-3.5 h-3.5" /> Detalles
                </button>
                <button
                  v-if="can('manage_routers')"
                  @click="deleteRouter(router.id)"
                  class="flex-1 min-w-[80px] px-2 py-2 text-xs font-medium rounded-lg flex justify-center items-center gap-1 bg-red-50 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                >
                  <icon-lucide-trash class="w-3.5 h-3.5" /> Eliminar
                </button>
              </div>
            </div>
            <div v-if="filteredRouters.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
              No se encontraron routers.
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Configurar WAN -->
      <div
        v-if="showWanModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="closeWanModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 m-4">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <icon-lucide-network class="w-6 h-6 text-purple-600" />
                Configurar Interfaz WAN
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ selectedRouter?.name }} - {{ selectedRouter?.ip }}
              </p>
            </div>
            <button
              @click="closeWanModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <icon-lucide-x class="w-6 h-6" />
            </button>
          </div>

          <!-- Contenido -->
          <div class="space-y-4">
            <!-- Cargando -->
            <div v-if="loadingInterfaces" class="flex flex-col items-center justify-center py-12">
              <icon-lucide-loader-2 class="w-12 h-12 text-purple-600 animate-spin mb-4" />
              <p class="text-gray-600 dark:text-gray-300">Obteniendo interfaces del router...</p>
            </div>

            <!-- Error -->
            <div v-else-if="interfacesError" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <icon-lucide-alert-triangle class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                <div class="flex-1 min-w-0">
                  <h4 class="font-medium text-red-800 dark:text-red-300">Error al obtener interfaces</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1 whitespace-pre-line">{{ interfacesError }}</p>

                  <!-- Per-attempt diagnostic table -->
                  <div v-if="interfaceAttempts && interfaceAttempts.length" class="mt-3 space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-red-700 dark:text-red-300">Métodos intentados</p>
                    <ul class="text-xs space-y-1">
                      <li
                        v-for="(att, i) in interfaceAttempts"
                        :key="i"
                        class="flex items-start gap-2 bg-white/50 dark:bg-gray-900/30 rounded px-2 py-1.5 border border-red-100 dark:border-red-900/40"
                      >
                        <span :class="att.success ? 'text-green-600' : 'text-red-500'" class="font-bold mt-0.5">
                          {{ att.success ? '✓' : '✗' }}
                        </span>
                        <span class="flex-1">
                          <span class="font-medium text-gray-800 dark:text-gray-200">{{ att.method }}</span>
                          <span v-if="att.port" class="text-gray-500 dark:text-gray-400"> · puerto {{ att.port }}</span>
                          <span class="block text-gray-600 dark:text-gray-400 mt-0.5">{{ att.message }}</span>
                        </span>
                      </li>
                    </ul>
                  </div>

                  <p v-if="interfacesHint" class="text-xs text-red-500 dark:text-red-400/80 mt-3 italic">
                    💡 {{ interfacesHint }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Lista de Interfaces -->
            <div v-else-if="interfaces.length > 0" class="space-y-3">
              <div class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Selecciona la interfaz WAN (conexión a internet):
              </div>

              <div class="max-h-96 overflow-y-auto space-y-2">
                <label
                  v-for="iface in interfaces"
                  :key="iface.name"
                  class="flex items-center justify-between p-4 border rounded-lg cursor-pointer transition-all"
                  :class="[
                    selectedWan === iface.name
                      ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                      : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700',
                    !iface.running || iface.disabled ? 'opacity-50' : ''
                  ]"
                >
                  <div class="flex items-center gap-3 flex-1">
                    <input
                      type="radio"
                      :value="iface.name"
                      v-model="selectedWan"
                      :disabled="!iface.running || iface.disabled"
                      class="w-4 h-4 text-purple-600 focus:ring-purple-500"
                    />
                    <div class="flex-1">
                      <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-800 dark:text-gray-100">{{ iface.name }}</span>
                        <span
                          v-if="iface.name === currentWan"
                          class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                        >
                          WAN Actual
                        </span>
                      </div>
                      <div class="flex items-center gap-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span class="capitalize">{{ iface.type }}</span>
                        <span v-if="iface.comment" class="text-gray-400">• {{ iface.comment }}</span>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <span
                      v-if="iface.running"
                      class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                    >
                      Activa
                    </span>
                    <span
                      v-else
                      class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400"
                    >
                      Inactiva
                    </span>
                  </div>
                </label>
              </div>
            </div>

            <!-- Sin interfaces — entrada manual -->
            <div v-else class="space-y-4">
              <div class="text-center text-gray-500 dark:text-gray-400 text-sm">
                No se pudieron obtener las interfaces del router automáticamente.
              </div>
              <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                <div class="flex items-start gap-3">
                  <icon-lucide-alert-triangle class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" />
                  <p class="text-sm text-yellow-700 dark:text-yellow-300">
                    Ingresa el nombre de la interfaz WAN manualmente.<br/>
                    <span class="text-xs text-yellow-600 dark:text-yellow-400">Ej: <code class="bg-yellow-100 dark:bg-yellow-900/40 px-1 rounded">ether1</code>, <code class="bg-yellow-100 dark:bg-yellow-900/40 px-1 rounded">sfp1</code>, <code class="bg-yellow-100 dark:bg-yellow-900/40 px-1 rounded">ether1-gateway</code></span>
                  </p>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Nombre de la interfaz WAN
                </label>
                <input
                  v-model="selectedWan"
                  type="text"
                  placeholder="Ej: ether1"
                  class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                />
              </div>
            </div>

          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeWanModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
              Cancelar
            </button>
            <button
              @click="saveWanInterface"
              :disabled="!selectedWan || savingWan"
              class="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <icon-lucide-loader-2 v-if="savingWan" class="w-4 h-4 animate-spin" />
              <icon-lucide-save v-else class="w-4 h-4" />
              {{ savingWan ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Configurar Reglas de Bloqueo -->
      <div
        v-if="showBlockRulesModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="closeBlockRulesModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl p-6 m-4">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <icon-lucide-shield-ban class="w-6 h-6 text-orange-600" />
                Configurar Reglas de Bloqueo
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Selecciona un router para aplicar reglas de firewall de bloqueo
              </p>
            </div>
            <button
              @click="closeBlockRulesModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <icon-lucide-x class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="space-y-4">
            <!-- Info Alert -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <icon-lucide-info class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-medium text-blue-800 dark:text-blue-300">¿Qué son las reglas de bloqueo?</h4>
                  <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    Se configurarán reglas de firewall en MikroTik que redirigen el tráfico HTTP/HTTPS de usuarios morosos
                    a un portal de pago. Se creará una address-list "ISPWATCH_SUSPENDIDOS" y reglas NAT que redirigen
                    a los morosos al portal, bloqueando el resto del tráfico.
                  </p>
                </div>
              </div>
            </div>

            <!-- Warning colapsable: aplicar solo una vez -->
            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-800 rounded-lg overflow-hidden">
              <button
                type="button"
                @click="showBlockRulesWarning = !showBlockRulesWarning"
                class="w-full flex items-center justify-between gap-3 px-4 py-3 hover:bg-amber-100/60 dark:hover:bg-amber-900/30 transition-colors"
                :aria-expanded="showBlockRulesWarning"
              >
                <div class="flex items-center gap-3">
                  <icon-lucide-triangle-alert class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                  <span class="font-medium text-amber-800 dark:text-amber-200 text-left">
                    Importante — aplicar solo una vez
                  </span>
                </div>
                <icon-lucide-chevron-down
                  class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 transition-transform"
                  :class="{ 'rotate-180': showBlockRulesWarning }"
                />
              </button>
              <div v-show="showBlockRulesWarning" class="px-4 pb-4 pt-1">
                <ul class="text-sm text-amber-700 dark:text-amber-300 space-y-1.5 list-disc list-inside pl-2">
                  <li>
                    Estas reglas <strong>solo deben aplicarse una vez por router</strong>. Cada click vuelve a crear las reglas en MikroTik, generando duplicados que pueden afectar el rendimiento del firewall.
                  </li>
                  <li>
                    Si las reglas fueron <strong>eliminadas por error</strong> (manualmente desde Winbox o por un reset), puedes volver a aplicarlas desde aquí sin problema.
                  </li>
                  <li>
                    Aplicar las reglas varias veces a propósito <strong>queda bajo tu responsabilidad</strong>. Tendrás que limpiar los duplicados manualmente en
                    <code class="px-1 py-0.5 bg-amber-100 dark:bg-amber-800/40 rounded text-xs">IP → Firewall → NAT / Filter Rules</code>.
                  </li>
                </ul>
              </div>
            </div>

            <!-- Router Selection -->
            <div v-if="!selectedBlockRouter" class="space-y-3">
              <div class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                Selecciona el router donde deseas configurar las reglas:
              </div>

              <div class="max-h-96 overflow-y-auto space-y-2">
                <button
                  v-for="router in routers"
                  :key="router.id"
                  @click="selectRouterForBlock(router)"
                  class="w-full flex items-center justify-between p-4 border rounded-lg cursor-pointer transition-all hover:border-orange-500 hover:bg-orange-50 dark:hover:bg-orange-900/20 border-gray-200 dark:border-gray-700"
                >
                  <div class="flex items-center gap-3 flex-1">
                    <icon-lucide-server class="w-5 h-5 text-gray-500" />
                    <div class="text-left">
                      <div class="font-medium text-gray-800 dark:text-gray-100">{{ router.name }}</div>
                      <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ router.ip }} • WAN: {{ router.wan_interface || 'No configurada' }}
                      </div>
                    </div>
                  </div>
                  <icon-lucide-chevron-right class="w-5 h-5 text-gray-400" />
                </button>
              </div>
            </div>

            <!-- Router Selected - Configuration -->
            <div v-else class="space-y-4">
              <!-- Selected Router Info -->
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 flex items-center justify-between">
                <div>
                  <div class="font-medium text-gray-800 dark:text-gray-100">{{ selectedBlockRouter.name }}</div>
                  <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ selectedBlockRouter.ip }} • WAN: {{ selectedBlockRouter.wan_interface || 'No configurada' }}
                  </div>
                </div>
                <button
                  @click="selectedBlockRouter = null"
                  class="text-sm text-orange-600 hover:text-orange-700 dark:text-orange-400"
                >
                  Cambiar router
                </button>
              </div>

              <!-- Warning if WAN not configured -->
              <div
                v-if="!selectedBlockRouter.wan_interface"
                class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4"
              >
                <div class="flex items-start gap-3">
                  <icon-lucide-alert-triangle class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-medium text-yellow-800 dark:text-yellow-300">WAN no configurada</h4>
                    <p class="text-sm text-yellow-600 dark:text-yellow-400 mt-1">
                      Este router no tiene una interfaz WAN configurada. Por favor configúrala primero usando el botón "WAN".
                    </p>
                  </div>
                </div>
              </div>

              <!-- Rules Configuration -->
              <div v-else class="space-y-4">
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                  <h4 class="font-medium text-gray-800 dark:text-gray-100 mb-3">Reglas que se aplicarán:</h4>
                  <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Crear address-list <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-600 rounded text-xs">ISPWATCH_SUSPENDIDOS</code> para gestionar usuarios morosos</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" />
                      <span>Regla filter <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-600 rounded text-xs">ALLOW-PORTAL</code> — permitir acceso al portal de pago para usuarios suspendidos</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
                      <span>Regla filter <code class="px-1 py-0.5 bg-gray-200 dark:bg-gray-600 rounded text-xs">DROP-SUSPENDED</code> — bloquear todo el tráfico de usuarios suspendidos</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Regla NAT para redirigir HTTP (puerto 80) al portal de pago</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Regla NAT para redirigir HTTPS (puerto 443) al portal de pago</span>
                    </li>
                  </ul>
                </div>

                <!-- Apply Button -->
                <div class="flex justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <button
                    @click="closeBlockRulesModal"
                    class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    @click="applyBlockRules"
                    :disabled="applyingRules"
                    class="px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                  >
                    <icon-lucide-loader-2 v-if="applyingRules" class="w-4 h-4 animate-spin" />
                    <icon-lucide-shield-check v-else class="w-4 h-4" />
                    {{ applyingRules ? 'Aplicando...' : 'Aplicar Reglas' }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Detalles del Router -->
      <div
        v-if="showDetailsModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="closeDetailsModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <icon-lucide-server class="w-6 h-6 text-cyan-600" />
                Detalles del Router
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Información básica del router
              </p>
            </div>
            <button
              @click="closeDetailsModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <icon-lucide-x class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div v-if="selectedDetailsRouter" class="space-y-4">
            <!-- Nombre y Estado -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-tag class="w-4 h-4" />
                  Nombre
                </div>
                <div class="font-medium text-gray-800 dark:text-gray-100">
                  {{ selectedDetailsRouter.name }}
                </div>
              </div>

              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-activity class="w-4 h-4" />
                  Estado
                </div>
                <div>
                  <span
                    class="inline-block px-3 py-1 text-sm font-semibold rounded-full"
                    :class="selectedDetailsRouter.status === 'active'
                      ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                      : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'"
                  >
                    {{ selectedDetailsRouter.status || '—' }}
                  </span>
                </div>
              </div>
            </div>

            <!-- IP y Usuario -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-network class="w-4 h-4" />
                  Dirección IP
                </div>
                <div class="font-medium text-gray-800 dark:text-gray-100 font-mono">
                  {{ selectedDetailsRouter.ip }}
                </div>
              </div>

              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-user class="w-4 h-4" />
                  Usuario RouterBOARD
                </div>
                <div class="font-medium text-gray-800 dark:text-gray-100">
                  {{ selectedDetailsRouter.user_rb }}
                </div>
              </div>
            </div>

            <!-- Interfaces -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-wifi class="w-4 h-4" />
                  Interfaz LAN
                </div>
                <div class="font-medium text-gray-800 dark:text-gray-100 font-mono">
                  {{ selectedDetailsRouter.lan_interface || '—' }}
                </div>
              </div>

              <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                  <icon-lucide-globe class="w-4 h-4" />
                  Interfaz WAN
                </div>
                <div class="font-medium text-gray-800 dark:text-gray-100 font-mono">
                  {{ selectedDetailsRouter.wan_interface || 'No configurada' }}
                </div>
              </div>
            </div>

            <!-- Firmware -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-cpu class="w-4 h-4" />
                Versión de Firmware
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ getScriptName(selectedDetailsRouter.firmware_version) }}
              </div>
            </div>

            <!-- Historial de Tráfico (WAN) -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                  <icon-lucide-activity class="w-4 h-4" />
                  Historial de Tráfico (WAN)
                </div>
                <span v-if="routerTraffic?.daily?.length" class="text-[11px] text-gray-400">
                  últimos {{ routerTraffic.days }} días
                </span>
              </div>

              <div v-if="trafficLoading" class="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">
                Cargando consumo…
              </div>

              <div v-else-if="routerTraffic && !routerTraffic.historial_trafico"
                class="text-sm text-gray-500 dark:text-gray-400">
                El historial de tráfico está <strong>desactivado</strong> para este router. Actívalo en
                «Editar Router» para empezar a registrar el consumo del enlace.
              </div>

              <template v-else-if="routerTraffic">
                <!-- Totales -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                  <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-100 dark:border-gray-700">
                    <div class="text-xs text-gray-400 mb-1">Hoy</div>
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                      <span class="text-blue-500">↓ {{ fmtBytes(routerTraffic.totals.today.rx_bytes) }}</span>
                      <span class="text-gray-300 dark:text-gray-600 mx-1">·</span>
                      <span class="text-emerald-500">↑ {{ fmtBytes(routerTraffic.totals.today.tx_bytes) }}</span>
                    </div>
                  </div>
                  <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-100 dark:border-gray-700">
                    <div class="text-xs text-gray-400 mb-1">Este mes</div>
                    <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                      <span class="text-blue-500">↓ {{ fmtBytes(routerTraffic.totals.month.rx_bytes) }}</span>
                      <span class="text-gray-300 dark:text-gray-600 mx-1">·</span>
                      <span class="text-emerald-500">↑ {{ fmtBytes(routerTraffic.totals.month.tx_bytes) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Mini gráfica de barras por día (consumo total = bajada + subida) -->
                <div v-if="trafficDaily.length" class="flex items-end gap-px h-24 mt-1">
                  <div v-for="d in trafficDaily" :key="d.day"
                    class="flex-1 min-w-[2px] bg-blue-500/60 hover:bg-blue-500 rounded-t transition-colors cursor-default"
                    :style="{ height: trafficBarHeight(d) }"
                    :title="`${d.day} — ↓ ${fmtBytes(d.rx_bytes)} / ↑ ${fmtBytes(d.tx_bytes)}`">
                  </div>
                </div>
                <div v-else class="text-sm text-gray-500 dark:text-gray-400 py-2">
                  Aún no hay datos de consumo. Se registran automáticamente cada 5 minutos.
                </div>
              </template>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeDetailsModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
              Cerrar
            </button>
            <button
              @click="editRouter(selectedDetailsRouter.id)"
              class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2"
            >
              <icon-lucide-pencil class="w-4 h-4" />
              Editar Router
            </button>
          </div>
        </div>
      </div>

      <!-- Modal Confirmar Eliminación -->
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        @click.self="closeDeleteModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6 m-4">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <icon-lucide-trash-2 class="w-6 h-6 text-red-600" />
                Eliminar Router
              </h2>
            </div>
            <button
              @click="closeDeleteModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <icon-lucide-x class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="space-y-4">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <icon-lucide-alert-triangle class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    Esta acción no se puede deshacer. El router <strong>"{{ routerToDelete?.name }}"</strong> será eliminado permanentemente.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeDeleteModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
              Cancelar
            </button>
            <button
              @click="confirmDelete"
              :disabled="deletingRouter"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <icon-lucide-loader-2 v-if="deletingRouter" class="w-4 h-4 animate-spin" />
              <icon-lucide-trash v-else class="w-4 h-4" />
              {{ deletingRouter ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </div>
        </div>
      </div>
      <!-- Modal Corte Automático Rápido -->
      <div
        v-if="showAutoCutModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeAutoCutModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg p-6 m-4">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <icon-lucide-scissors class="w-6 h-6 text-red-600" />
                Corte Automático
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Router: <strong>{{ autoCutRouter?.name }}</strong>
              </p>
            </div>
            <button @click="closeAutoCutModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
              <icon-lucide-x class="w-6 h-6" />
            </button>
          </div>

          <!-- Contenido -->
          <div class="space-y-4">
            <!-- Estado inicial -->
            <div v-if="!autoCutResult && !runningAutoCut">
              <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-start gap-3">
                  <icon-lucide-alert-triangle class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                  <div>
                    <h4 class="font-medium text-red-800 dark:text-red-300">Ejecutar corte manual ahora</h4>
                    <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                      Se suspenderán todos los clientes de este router que tengan facturas vencidas
                      suficientes según la configuración de facturación. Esta acción ignora la
                      restricción de hora/día de corte.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Loading -->
            <div v-if="runningAutoCut" class="flex flex-col items-center justify-center py-10">
              <div class="w-14 h-14 border-4 border-red-500 border-t-transparent rounded-full animate-spin mb-4"></div>
              <p class="text-gray-600 dark:text-gray-300">Ejecutando corte automático...</p>
            </div>

            <!-- Resultado -->
            <div v-if="autoCutResult && !runningAutoCut" class="space-y-3">
              <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3">
                <p class="text-sm font-medium text-green-800 dark:text-green-300">Corte completado</p>
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 text-center">
                  <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ autoCutResult.suspended }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Suspendidos</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 text-center">
                  <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ autoCutResult.manual_pending }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pend. Manual</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 text-center">
                  <div class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ autoCutResult.no_action }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Sin Acción</div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 text-center">
                  <div class="text-2xl font-bold" :class="autoCutResult.errors > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400'">{{ autoCutResult.errors }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Errores</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="closeAutoCutModal"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
            >
              Cerrar
            </button>
            <button
              v-if="!autoCutResult"
              @click="runAutoCut"
              :disabled="runningAutoCut"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <icon-lucide-loader-2 v-if="runningAutoCut" class="w-4 h-4 animate-spin" />
              <icon-lucide-scissors v-else class="w-4 h-4" />
              {{ runningAutoCut ? 'Ejecutando...' : 'Ejecutar Corte Ahora' }}
            </button>
            <button
              v-if="autoCutResult"
              @click="runAutoCut"
              :disabled="runningAutoCut"
              class="px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <icon-lucide-refresh-cw class="w-4 h-4" />
              Ejecutar de nuevo
            </button>
          </div>
        </div>
      </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'
import api from '@/services/api.js'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()
const router = useRouter()
const search = ref('')
const routers = ref([])
const loading = ref(true)
const toast = ref(null)

// Estados del modal WAN
const showWanModal = ref(false)
const selectedRouter = ref(null)
const interfaces = ref([])
const selectedWan = ref(null)
const currentWan = ref(null)
const loadingInterfaces = ref(false)
const interfacesError = ref(null)
const interfacesHint = ref(null)
const interfaceAttempts = ref([])
const savingWan = ref(false)

// Estados del modal Reglas de Bloqueo
const showBlockRulesModal = ref(false)
const selectedBlockRouter = ref(null)
const applyingRules = ref(false)
const showBlockRulesWarning = ref(false)

// Estados del modal Eliminar Router
const showDeleteModal = ref(false)
const routerToDelete = ref(null)
const deletingRouter = ref(false)

// Estados del modal Detalles
const showDetailsModal = ref(false)
const selectedDetailsRouter = ref(null)

// Historial de tráfico WAN del router (cargado al abrir el modal de detalles)
const routerTraffic = ref(null)
const trafficLoading = ref(false)

const loadRouterTraffic = async (id) => {
  routerTraffic.value = null
  trafficLoading.value = true
  try {
    const { data } = await api.routers.getTraffic(id, { days: 30 })
    routerTraffic.value = data
  } catch (e) {
    routerTraffic.value = null
  } finally {
    trafficLoading.value = false
  }
}

const fmtBytes = (n) => {
  const b = Number(n) || 0
  if (b >= 1e12) return (b / 1e12).toFixed(2) + ' TB'
  if (b >= 1e9)  return (b / 1e9).toFixed(2) + ' GB'
  if (b >= 1e6)  return (b / 1e6).toFixed(1) + ' MB'
  if (b >= 1e3)  return (b / 1e3).toFixed(0) + ' KB'
  return b + ' B'
}

const trafficDaily = computed(() => routerTraffic.value?.daily ?? [])
const trafficMaxTotal = computed(() =>
  Math.max(1, ...trafficDaily.value.map(d => (d.rx_bytes || 0) + (d.tx_bytes || 0)))
)
const trafficBarHeight = (d) => {
  const total = (d.rx_bytes || 0) + (d.tx_bytes || 0)
  const pct = (total / trafficMaxTotal.value) * 100
  return Math.max(total > 0 ? 6 : 2, pct) + '%'
}

// Estados del modal Corte Automático
const showAutoCutModal = ref(false)
const autoCutRouter = ref(null)
const runningAutoCut = ref(false)
const autoCutResult = ref(null)

const openAutoCutModal = (router) => {
  autoCutRouter.value = router
  autoCutResult.value = null
  showAutoCutModal.value = true
}

const closeAutoCutModal = () => {
  showAutoCutModal.value = false
  autoCutRouter.value = null
  autoCutResult.value = null
}

const runAutoCut = async () => {
  if (!autoCutRouter.value) return
  runningAutoCut.value = true
  autoCutResult.value = null
  try {
    const response = await api.post('/billing/run-auto-cut', {
      router_id: autoCutRouter.value.id
    })
    autoCutResult.value = response.data.stats
    toast.value?.success('Corte completado', `${response.data.stats.suspended} cliente(s) suspendidos.`)
  } catch (err) {
    console.error('Error al ejecutar corte:', err)
    toast.value?.error('Error', 'No se pudo ejecutar el corte automático.')
  } finally {
    runningAutoCut.value = false
  }
}

// 🔹 Navegar a la vista de agregar router
const goToAddRouter = () => {
  router.push('/routers/add')
}


// 🔹 Cargar routers desde Supabase filtrados por tenant
const loadRouters = async () => {
  loading.value = true
  // Obtener los datos del usuario almacenados
  const userData =
    JSON.parse(localStorage.getItem("userData")) ??
    JSON.parse(sessionStorage.getItem("userData"))

  if (!userData || !userData.tenant_id) {
    console.error("⚠️ No se encontró tenant_id del usuario autenticado.")
    return
  }

  const tenant_id = userData.tenant_id

  // Consultar routers por tenant
  const { data, error } = await supabase
    .from("router")
    .select("id, name, ip, user_rb, lan_interface, wan_interface, firmware_version, status")
    .eq("tenant_id", tenant_id)

  if (error) {
    console.error("❌ Error al cargar routers:", error.message)
    return
  }

  routers.value = data || []
  
  // Cargar versiones de scripts
  await loadScriptVersions()
  
  loading.value = false
}

const scriptVersions = ref([])

// 🔹 Cargar versiones de scripts
const loadScriptVersions = async () => {
    const { data, error } = await supabase
        .from('script_version')
        .select('id, version')
    
    if (error) {
        console.error('❌ Error al cargar versiones de script:', error.message)
        return
    }
    
    scriptVersions.value = data || []
}

// 🔹 Obtener nombre de la versión
const getScriptName = (id) => {
    if (!id) return '—'
    const script = scriptVersions.value.find(v => v.id == id)
    return script ? script.version : id // Retorna el nombre o el ID si no se encuentra
}


onMounted(loadRouters)

// ==============================
// FUNCIONES MODAL ELIMINAR ROUTER
// ==============================

// Abrir modal de confirmación para eliminar
const deleteRouter = (id) => {
  const routerData = routers.value.find(r => r.id === id)
  if (routerData) {
    routerToDelete.value = routerData
    showDeleteModal.value = true
  }
}

// Cerrar modal de eliminar
const closeDeleteModal = () => {
  showDeleteModal.value = false
  routerToDelete.value = null
}

// Confirmar eliminación
const confirmDelete = async () => {
  if (!routerToDelete.value) return
  
  deletingRouter.value = true
  
  try {
    const { error } = await supabase.from('router').delete().eq('id', routerToDelete.value.id)
    
    if (error) {
      console.error('❌ Error al eliminar router:', error.message)
      toast.value?.error('Error al eliminar', 'No se pudo eliminar el router. Intenta de nuevo.')
      return
    }
    
    routers.value = routers.value.filter(r => r.id !== routerToDelete.value.id)
    closeDeleteModal()
    toast.value?.success('Router eliminado', 'El router ha sido eliminado correctamente')
  } catch (error) {
    console.error('Error:', error)
    toast.value?.error('Error', 'Ocurrió un error inesperado')
  } finally {
    deletingRouter.value = false
  }
}

// 🔹 Filtro de búsqueda
const filteredRouters = computed(() =>
  routers.value.filter(r =>
    [r.name, r.ip, r.user_rb, r.status]
      .filter(Boolean)
      .some(f => f.toLowerCase().includes(search.value.toLowerCase()))
  )
)

const clearSearch = () => (search.value = '')

// Export Helper
const generateCSV = (withBOM = false) => {
  if (filteredRouters.value.length === 0) {
    alert("No hay datos para exportar")
    return null
  }

  // Headers
  const headers = ['Nombre', 'IP', 'Usuario RB', 'Interfaz LAN', 'Interfaz WAN', 'Versión Firmware', 'Estado']
  
  // Rows
  const rows = filteredRouters.value.map(router => [
    `"${router.name || ''}"`,
    `"${router.ip || ''}"`,
    `"${router.user_rb || ''}"`,
    `"${router.lan_interface || ''}"`,
    `"${router.wan_interface || ''}"`,
    `"${getScriptName(router.firmware_version) || ''}"`,
    `"${router.status || ''}"`
  ])

  // Combine headers and rows
  const csvContent = [
    headers.join(','), 
    ...rows.map(row => row.join(','))
  ].join('\n')

  return withBOM ? '\uFEFF' + csvContent : csvContent
}

const downloadFile = (content, filename, mimeType) => {
  if (!content) return
  
  const blob = new Blob([content], { type: mimeType })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.setAttribute('href', url)
  link.setAttribute('download', filename)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

// Export to CSV
const exportToCSV = () => {
  const content = generateCSV(false)
  const date = new Date().toISOString().split('T')[0]
  downloadFile(content, `routers_list_${date}.csv`, 'text/csv;charset=utf-8;')
}

// Export to Excel (XLSX)
const exportToExcel = () => {
  if (filteredRouters.value.length === 0) {
    alert("No hay datos para exportar")
    return
  }

  // Prepare data for Excel
  const data = filteredRouters.value.map(router => ({
    'Nombre': router.name || '',
    'IP': router.ip || '',
    'Usuario RB': router.user_rb || '',
    'Interfaz LAN': router.lan_interface || '',
    'Interfaz WAN': router.wan_interface || '',
    'Versión Firmware': getScriptName(router.firmware_version) || '',
    'Estado': router.status || ''
  }))

  // Create worksheet from data
  const worksheet = XLSX.utils.json_to_sheet(data)
  
  // Set column widths for better readability
  worksheet['!cols'] = [
    { wch: 25 }, // Nombre
    { wch: 15 }, // IP
    { wch: 15 }, // Usuario
    { wch: 15 }, // LAN
    { wch: 15 }, // WAN
    { wch: 15 }, // Firmware
    { wch: 10 }  // Estado
  ]

  // Create workbook and add worksheet
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Routers')

  // Generate filename with current date
  const date = new Date().toISOString().split('T')[0]
  const filename = `routers_excel_${date}.xlsx`

  // Write and download file
  XLSX.writeFile(workbook, filename)
}

// ==============================
// FUNCIONES MODAL WAN
// ==============================

// Abrir modal y cargar interfaces
const openWanModal = async (routerData) => {
  selectedRouter.value = routerData
  showWanModal.value = true
  interfaces.value = []
  selectedWan.value = null
  currentWan.value = null
  interfacesError.value = null
  interfacesHint.value = null
  interfaceAttempts.value = []
  loadingInterfaces.value = true

  try {
    const { data } = await api.routers.getInterfaces(routerData.id)

    if (data.success) {
      interfaces.value = data.interfaces
      currentWan.value = data.current_wan
      // Pre-seleccionar la WAN actual si existe
      if (data.current_wan) {
        selectedWan.value = data.current_wan
      }
    } else {
      interfacesError.value = data.message || 'Error al obtener interfaces'
      interfacesHint.value = data.hint || null
      interfaceAttempts.value = data.attempts || []
    }
  } catch (error) {
    console.error('Error al cargar interfaces:', error)
    interfacesError.value = error.response?.data?.message || 'Error de conexión al obtener interfaces'
    interfacesHint.value = error.response?.data?.hint || null
    interfaceAttempts.value = error.response?.data?.attempts || []
  } finally {
    loadingInterfaces.value = false
  }
}

// Cerrar modal
const closeWanModal = () => {
  showWanModal.value = false
  selectedRouter.value = null
  interfaces.value = []
  selectedWan.value = null
  currentWan.value = null
  interfacesError.value = null
  interfacesHint.value = null
  interfaceAttempts.value = []
}

// Guardar interfaz WAN seleccionada
const saveWanInterface = async () => {
  if (!selectedWan.value || !selectedRouter.value) return

  savingWan.value = true

  try {
    const { data } = await api.routers.setWanInterface(selectedRouter.value.id, selectedWan.value)

    if (data.success) {
      toast.value?.success('Interfaz WAN configurada', 'La interfaz WAN se ha guardado correctamente')
      closeWanModal()
      // Recargar lista de routers para actualizar datos
      await loadRouters()
    } else {
      toast.value?.error('Error al guardar', data.message || 'Error desconocido al configurar WAN')
    }
  } catch (error) {
    console.error('Error al guardar WAN:', error)
    toast.value?.error('Error de conexión', error.response?.data?.message || 'No se pudo conectar al servidor para guardar la WAN')
  } finally {
    savingWan.value = false
  }
}

// ==============================
// FUNCIONES MODAL REGLAS DE BLOQUEO
// ==============================

// Abrir modal de reglas de bloqueo
const openBlockRulesInfo = () => {
  showBlockRulesModal.value = true
  selectedBlockRouter.value = null
  showBlockRulesWarning.value = false
}

// Cerrar modal de reglas de bloqueo
const closeBlockRulesModal = () => {
  showBlockRulesModal.value = false
  selectedBlockRouter.value = null
  showBlockRulesWarning.value = false
}

// Seleccionar router para aplicar reglas
const selectRouterForBlock = (routerData) => {
  selectedBlockRouter.value = routerData
}

// Aplicar reglas de bloqueo al router
const applyBlockRules = async () => {
  if (!selectedBlockRouter.value || !selectedBlockRouter.value.wan_interface) return

  applyingRules.value = true

  try {
    const { data } = await api.routers.applyBlockRules(selectedBlockRouter.value.id)

    if (data.success) {
      toast.value?.success('Reglas aplicadas', 'Las reglas de bloqueo se configuraron correctamente en el router')
      closeBlockRulesModal()
    } else {
      toast.value?.error('Error al aplicar reglas', data.message || 'Error desconocido')
    }
  } catch (error) {
    console.error('Error al aplicar reglas de bloqueo:', error)
    toast.value?.error('Error de conexión', error.response?.data?.message || 'No se pudo conectar al servidor para aplicar las reglas')
  } finally {
    applyingRules.value = false
  }
}

// ==============================
// FUNCIONES MODAL DETALLES
// ==============================

// Abrir modal de detalles
const openDetailsModal = (routerData) => {
  selectedDetailsRouter.value = routerData
  showDetailsModal.value = true
  loadRouterTraffic(routerData.id)
}

// Cerrar modal de detalles
const closeDetailsModal = () => {
  showDetailsModal.value = false
  selectedDetailsRouter.value = null
  routerTraffic.value = null
}

// Editar router desde el modal
const editRouter = (id) => {
  router.push({ name: 'RouterEdit', params: { id } })
}

</script>
