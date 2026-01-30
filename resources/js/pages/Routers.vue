<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Notification Toast -->
    <NotificationToast ref="toast" />

    <!-- Contenido principal -->
    <main class="flex-1 p-6 overflow-y-auto">
      <!-- Encabezado -->
      <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
              <v-icon name="pr-server" class="text-blue-600 w-7 h-7" />
              Routers del Sistema
            </h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">
              Gestión de routers y configuración por zonas.
            </p>
        </div>
        <!-- Botón Agregar Router -->
          <button
            @click="goToAddRouter"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center gap-2 transition-all"
          >
            <icon-lucide-plus class="w-4 h-4" />
            Agregar Router
          </button>
      </div>

      <!-- Tarjeta principal -->
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow p-6">
        <!-- Filtros -->
        <div class="flex flex-wrap items-center justify-between mb-4 gap-4">
          <!-- Lado Izquierdo: Búsqueda y Limpiar -->
          <div class="flex items-center gap-2 w-full sm:w-auto">
            <input
              v-model="search"
              type="text"
              placeholder="Buscar por nombre, IP o usuario..."
              class="border border-gray-300 dark:border-gray-700 rounded-xl px-4 py-2 w-full sm:w-80 focus:ring-2 focus:ring-blue-300 outline-none dark:bg-gray-900 dark:text-white"
            />
            <button
              @click="clearSearch"
              class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-3 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all whitespace-nowrap"
            >
              Limpiar
            </button>
          </div>

          <!-- Lado Derecho: Acciones -->
            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
               <!-- Export CSV -->
            <button
              @click="exportToCSV"
              class="text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="w-4 h-4" />
              CSV
            </button>

             <!-- Export Excel -->
            <button
              @click="exportToExcel"
              class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 transition-all flex items-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="w-4 h-4" />
              Excel
            </button>

              <button
                @click="openBlockRulesInfo"
                class="text-sm bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition-all shadow-md"
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

        <!-- Tabla -->
        <div v-if="!loading" class="overflow-x-auto">
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
                class="border-b border-gray-200 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-gray-700/40 transition-all"
              >
                <td class="py-3 px-4 font-medium text-gray-800 dark:text-gray-100">{{ router.name }}</td>
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
                <td class="py-3 px-4 flex gap-2">
                  <!-- Botón Editar -->
                  <button
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

                  <!-- Botón Detalles -->
                  <button
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
                <div>
                  <h4 class="font-medium text-red-800 dark:text-red-300">Error al obtener interfaces</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">{{ interfacesError }}</p>
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

            <!-- Sin interfaces -->
            <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
              No se encontraron interfaces disponibles
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
                      <span>Crear address-list "ISPWATCH_SUSPENDIDOS" para gestionar usuarios morosos</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Regla NAT para redirigir HTTP (puerto 80) al portal de pago</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Regla NAT para redirigir HTTPS (puerto 443) al portal de pago</span>
                    </li>
                    <li class="flex items-start gap-2">
                      <icon-lucide-check class="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                      <span>Regla de firewall para bloquear todo el resto del tráfico hacia {{ selectedBlockRouter.wan_interface }}</span>
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
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'
import api from '@/services/api.js'

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
const savingWan = ref(false)

// Estados del modal Reglas de Bloqueo
const showBlockRulesModal = ref(false)
const selectedBlockRouter = ref(null)
const applyingRules = ref(false)

// Estados del modal Eliminar Router
const showDeleteModal = ref(false)
const routerToDelete = ref(null)
const deletingRouter = ref(false)

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
    }
  } catch (error) {
    console.error('Error al cargar interfaces:', error)
    interfacesError.value = error.response?.data?.message || 'Error de conexión al obtener interfaces'
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
}

// Cerrar modal de reglas de bloqueo
const closeBlockRulesModal = () => {
  showBlockRulesModal.value = false
  selectedBlockRouter.value = null
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

</script>
