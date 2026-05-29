<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />
        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Elementos de Red</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Sectoriales, switches y nodos de la infraestructura</p>
        </div>
        <button
            v-if="can('view_sectorials')"
            @click="router.push('/sectorials/create')"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg shadow-md flex items-center justify-center gap-2 transition-all w-full sm:w-auto"
        >
            <icon-lucide-plus class="w-4 h-4" />
            Agregar Elemento
        </button>
        </div>

        <!-- Filtros y Acciones -->
        <div class="flex flex-col md:flex-row items-stretch md:items-center justify-between mb-6 gap-4">
          <!-- Lado Izquierdo: Buscador y Limpiar -->
          <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full md:w-auto flex-1">
            <div class="relative w-full sm:max-w-md">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, IP o usuario..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 px-4 py-2.5 sm:py-2 pl-10 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 shadow-sm"
                />
                <v-icon name="io-search" class="absolute left-3 top-3 sm:top-2.5 w-5 h-5 text-gray-400" />
            </div>
            
            <button
              @click="clearSearch"
              class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-4 py-2.5 sm:py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-all font-medium shadow-sm sm:h-[42px] text-center"
            >
              Limpiar
            </button>

            <!-- Filtro por tipo de elemento -->
            <div class="flex flex-wrap sm:flex-nowrap items-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-lg overflow-hidden shadow-sm sm:h-[42px]">
              <button
                v-for="opt in elementFilters"
                :key="opt.value"
                @click="elementFilter = opt.value"
                :class="[
                  'px-3 py-2 sm:h-full text-xs font-medium transition-all flex items-center gap-1.5 flex-1 sm:flex-none justify-center',
                  elementFilter === opt.value
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                ]"
              >
                <v-icon v-if="opt.icon" :name="opt.icon" class="w-3.5 h-3.5" />
                {{ opt.label }}
              </button>
            </div>
          </div>

          <!-- Lado Derecho: Botones Exportar -->
          <div class="flex items-center gap-2 w-full md:w-auto justify-stretch sm:justify-end">
            <!-- Export CSV -->
            <button
              @click="exportToCSV"
              class="flex-1 sm:flex-none text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2.5 sm:py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center justify-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50 shadow-sm sm:h-[42px]"
              title="Exportar archivo CSV puro"
            >
              <icon-lucide-file-text class="w-4 h-4" />
              CSV
            </button>

             <!-- Export Excel -->
            <button
              @click="exportToExcel"
              class="flex-1 sm:flex-none text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2.5 sm:py-2 rounded-lg hover:bg-green-100 transition-all flex items-center justify-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50 shadow-sm sm:h-[42px]"
              title="Exportar archivo compatible con Excel"
            >
              <icon-lucide-file-spreadsheet class="w-4 h-4" />
              Excel
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
        <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando sectoriales...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
        {{ error }}
        </div>

        <!-- Tabla / Cards -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">
        
        <!-- Desktop table -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Elemento</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nombre</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Subtipo</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Router</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Frecuencia</th>
                <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nodo Torre</th>
                <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                <tr v-for="sectorial in filteredSectorials" :key="sectorial.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 transition cursor-pointer" @click="router.push(`/sectorials/${sectorial.id}`)">
                <td class="px-6 py-4">
                    <span :class="elementBadge(sectorial.element_type)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border">
                        <v-icon :name="elementIcon(sectorial.element_type)" class="w-3.5 h-3.5" />
                        {{ elementLabel(sectorial.element_type) }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-800 dark:text-white font-medium">{{ sectorial.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.type || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                    <span v-if="getRouterName(sectorial.zona_id)" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-md text-xs font-medium">
                        <v-icon name="bi-router" class="w-3 h-3" />
                        {{ getRouterName(sectorial.zona_id) }}
                    </span>
                    <span v-else class="text-gray-400">-</span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.frequency || '-' }}</td>
                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ sectorial.node_tower || '-' }}</td>
                <td class="px-6 py-4" @click.stop>
                    <div class="flex justify-center gap-2 flex-wrap">
                    <button
                        @click="router.push(`/sectorials/${sectorial.id}`)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-cyan-50 text-cyan-700 border border-cyan-200
                            hover:bg-cyan-100 hover:scale-[1.03] transition-all
                            dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800 dark:hover:bg-cyan-800/50"
                    >
                        <icon-lucide-eye class="w-4 h-4" />
                        Ver
                    </button>
                    <button
                        v-if="can('routers.edit')"
                        @click="router.push(`/sectorials/${sectorial.id}/edit`)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-blue-50 text-blue-700 border border-blue-200
                            hover:bg-blue-100 hover:scale-[1.03] transition-all
                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                    >
                    <icon-lucide-pencil class="w-4 h-4" />
                        Editar
                    </button>
                    <button
                        v-if="can('routers.delete')"
                        @click="deleteSectorial(sectorial.id)"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-red-50 text-red-700 border border-red-200
                            hover:bg-red-100 hover:scale-[1.03] transition-all
                            dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50"
                    >
                        <icon-lucide-trash-2 class="w-4 h-4" />
                        Eliminar
                    </button>
                    </div>
                </td>
                </tr>

                <tr v-if="filteredSectorials.length === 0 && !loading">
                <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    {{ searchQuery || elementFilter !== 'all' ? 'No se encontraron resultados' : 'No hay elementos registrados' }}
                </td>
                </tr>
            </tbody>
            </table>
        </div>

        <!-- Mobile cards -->
        <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
            <div v-for="sectorial in filteredSectorials" :key="sectorial.id" class="p-4" @click="router.push(`/sectorials/${sectorial.id}`)">
                <div class="space-y-3">
                    <div class="flex justify-between items-start gap-2">
                        <div class="min-w-0">
                            <span :class="elementBadge(sectorial.element_type)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border mb-1">
                                <v-icon :name="elementIcon(sectorial.element_type)" class="w-3 h-3" />
                                {{ elementLabel(sectorial.element_type) }}
                            </span>
                            <h3 class="font-semibold text-gray-800 dark:text-white text-sm leading-snug">{{ sectorial.name }}</h3>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-1.5 text-xs">
                        <div><span class="text-gray-400">Subtipo:</span> <span class="ml-1 text-gray-800 dark:text-gray-200">{{ sectorial.type || '-' }}</span></div>
                        <div><span class="text-gray-400">Frecuencia:</span> <span class="ml-1 text-gray-800 dark:text-gray-200">{{ sectorial.frequency || '-' }}</span></div>
                        <div class="col-span-2"><span class="text-gray-400">Nodo:</span> <span class="ml-1 text-gray-800 dark:text-gray-200">{{ sectorial.node_tower || '-' }}</span></div>
                        <div class="col-span-2 mt-1">
                            <span v-if="getRouterName(sectorial.zona_id)" class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-md text-[10px] font-medium border border-green-200 dark:border-green-800">
                                <v-icon name="bi-router" class="w-3 h-3" />
                                {{ getRouterName(sectorial.zona_id) }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100 dark:border-gray-700" @click.stop>
                        <button
                            @click="router.push(`/sectorials/${sectorial.id}`)"
                            class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-cyan-50 text-cyan-700 border border-cyan-200 hover:bg-cyan-100 transition-all
                                dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800"
                        >
                            <icon-lucide-eye class="w-3.5 h-3.5" /> Ver
                        </button>
                        <button
                            v-if="can('routers.edit')"
                            @click="router.push(`/sectorials/${sectorial.id}/edit`)"
                            class="flex-1 min-w-[80px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-all
                                dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800"
                        >
                            <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                        </button>
                        <button
                            v-if="can('routers.delete')"
                            @click="deleteSectorial(sectorial.id)"
                            class="px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                                bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-all
                                dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                        >
                            <icon-lucide-trash-2 class="w-3.5 h-3.5" />
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="filteredSectorials.length === 0 && !loading" class="p-8 text-center text-gray-500 dark:text-gray-400">
                {{ searchQuery || elementFilter !== 'all' ? 'No se encontraron resultados' : 'No hay elementos registrados' }}
            </div>
        </div>
        </div>
    </div>

    <!-- Modal Detalles de la Sectorial -->
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
              <icon-lucide-radio-tower class="w-6 h-6 text-cyan-600" />
              Detalles de la Sectorial
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              Información completa de la sectorial
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
        <div v-if="selectedDetailsSectorial" class="space-y-4">
          <!-- Nombre y Tipo -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-tag class="w-4 h-4" />
                Nombre
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ selectedDetailsSectorial.name }}
              </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-layers class="w-4 h-4" />
                Tipo
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ selectedDetailsSectorial.type || '—' }}
              </div>
            </div>
          </div>

          <!-- Usuario RB y Frecuencia -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-user class="w-4 h-4" />
                Usuario RouterBOARD
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ selectedDetailsSectorial.user_rb || '—' }}
              </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-radio class="w-4 h-4" />
                Frecuencia
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ selectedDetailsSectorial.frequency || '—' }}
              </div>
            </div>
          </div>

          <!-- Router y Nodo Torre -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-server class="w-4 h-4" />
                Router Asociado
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ getRouterName(selectedDetailsSectorial.zona_id) || '—' }}
              </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
              <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-1">
                <icon-lucide-tower-control class="w-4 h-4" />
                Nodo Torre
              </div>
              <div class="font-medium text-gray-800 dark:text-gray-100">
                {{ selectedDetailsSectorial.node_tower || '—' }}
              </div>
            </div>
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
            @click="router.push(`/sectorials/${selectedDetailsSectorial?.id}/edit`)"
            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2"
          >
            <icon-lucide-pencil class="w-4 h-4" />
            Editar Sectorial
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
              Eliminar Sectorial
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
                  Esta acción no se puede deshacer. La sectorial <strong>"{{ sectorialToDelete?.name }}"</strong> será eliminada permanentemente.
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
            :disabled="deletingSectorial"
            class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
          >
            <icon-lucide-loader-2 v-if="deletingSectorial" class="w-4 h-4 animate-spin" />
            <icon-lucide-trash v-else class="w-4 h-4" />
            {{ deletingSectorial ? 'Eliminando...' : 'Eliminar' }}
          </button>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { supabase } from '@/supabase.js'
import api from '../services/api'
import * as XLSX from 'xlsx'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const router = useRouter()

const sectorials = ref([])
const routers = ref([])
const loading = ref(true)
const error = ref('')
const searchQuery = ref('')
const elementFilter = ref('all')
const toast = ref(null)

const elementFilters = [
    { value: 'all',       label: 'Todos',     icon: '' },
    { value: 'sectorial', label: 'Sectoriales', icon: 'md-router' },
    { value: 'switch',    label: 'Switches',  icon: 'bi-hdd-network' },
    { value: 'nodo',      label: 'Nodos',     icon: 'bi-diagram-3' },
]

const ELEMENT_META = {
    sectorial: { label: 'Sectorial', icon: 'md-router',       color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800' },
    switch:    { label: 'Switch',    icon: 'bi-hdd-network',  color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800' },
    nodo:      { label: 'Nodo',      icon: 'bi-diagram-3',    color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800' },
}
const elementLabel = (t) => ELEMENT_META[t]?.label || 'Sectorial'
const elementIcon  = (t) => ELEMENT_META[t]?.icon  || 'md-router'
const elementBadge = (t) => ELEMENT_META[t]?.color || ELEMENT_META.sectorial.color

// Estados del modal eliminar
const showDeleteModal = ref(false)
const sectorialToDelete = ref(null)
const deletingSectorial = ref(false)

// Estados del modal detalles
const showDetailsModal = ref(false)
const selectedDetailsSectorial = ref(null)

// Computed para filtrar sectoriales
const filteredSectorials = computed(() => {
    let list = sectorials.value

    if (elementFilter.value !== 'all') {
        list = list.filter(s => (s.element_type || 'sectorial') === elementFilter.value)
    }

    if (!searchQuery.value) return list

    const query = searchQuery.value.toLowerCase().trim()
    return list.filter(sectorial => {
        const name = sectorial.name?.toLowerCase() || ''
        const type = sectorial.type?.toLowerCase() || ''
        const userRb = sectorial.user_rb?.toLowerCase() || ''
        const nodeTower = sectorial.node_tower?.toLowerCase() || ''

        return (
        name.includes(query) ||
        type.includes(query) ||
        userRb.includes(query) ||
        nodeTower.includes(query)
        )
    })
})

// Función para obtener el nombre del router por ID
const getRouterName = (zonaId) => {
    if (!zonaId) return null
    const router = routers.value.find(r => r.id === zonaId)
    return router ? router.name : null
}

// Cargar routers del tenant actual
const loadRouters = async () => {
    try {
        const userData = 
            JSON.parse(localStorage.getItem("userData")) ??
            JSON.parse(sessionStorage.getItem("userData"))

        if (!userData?.tenant_id) {
            console.error("No se encontró tenant_id")
            return
        }

        const { data, error: fetchError } = await supabase
            .from("router")
            .select("id, name, ip")
            .eq("tenant_id", userData.tenant_id)

        if (fetchError) {
            console.error("Error al cargar routers:", fetchError.message)
            return
        }

        routers.value = data || []
    } catch (err) {
        console.error('Error al cargar routers:', err)
    }
}

const loadSectorials = async () => {
    try {
        loading.value = true
        const response = await api.sectorials.getAll()
        sectorials.value = response.data
    } catch (err) {
        console.error('Error al cargar sectoriales:', err)
        error.value = 'Error al cargar las sectoriales'
    } finally {
        loading.value = false
    }
}

// Abrir modal de confirmación para eliminar
const deleteSectorial = (id) => {
    const sectorialData = sectorials.value.find(s => s.id === id)
    if (sectorialData) {
        sectorialToDelete.value = sectorialData
        showDeleteModal.value = true
    }
}

// Cerrar modal de eliminar
const closeDeleteModal = () => {
    showDeleteModal.value = false
    sectorialToDelete.value = null
}

// Confirmar eliminación
const confirmDelete = async () => {
    if (!sectorialToDelete.value) return
    
    deletingSectorial.value = true
    
    try {
        await api.sectorials.delete(sectorialToDelete.value.id)
        
        toast.value?.success(
            'Sectorial eliminada',
            `La sectorial "${sectorialToDelete.value.name}" ha sido eliminada correctamente`
        )
        
        closeDeleteModal()
        loadSectorials()
    } catch (err) {
        console.error('Error al eliminar sectorial:', err)
        toast.value?.error(
            'Error al eliminar',
            'No se pudo eliminar la sectorial. Intenta de nuevo.'
        )
    } finally {
        deletingSectorial.value = false
    }
}

// ==============================
// FUNCIONES MODAL DETALLES
// ==============================

// Abrir modal de detalles
const openDetailsModal = (sectorialData) => {
    selectedDetailsSectorial.value = sectorialData
    showDetailsModal.value = true
}

// Cerrar modal de detalles
const closeDetailsModal = () => {
    showDetailsModal.value = false
    selectedDetailsSectorial.value = null
}

const clearSearch = () => {
    searchQuery.value = ''
}

// Export Helper
const generateCSV = (withBOM = false) => {
  if (filteredSectorials.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return null
  }

  // Headers
  const headers = ['Nombre', 'Tipo', 'Usuario RB', 'Zona ID', 'Frecuencia', 'Nodo Torre']
  
  // Rows
  const rows = filteredSectorials.value.map(s => [
    `"${s.name || ''}"`,
    `"${s.type || ''}"`,
    `"${s.user_rb || ''}"`,
    `"${s.zona_id || ''}"`,
    `"${s.frequency || ''}"`,
    `"${s.node_tower || ''}"`
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
  downloadFile(content, `sectorials_list_${date}.csv`, 'text/csv;charset=utf-8;')
}

// Export to Excel (XLSX)
const exportToExcel = () => {
  if (filteredSectorials.value.length === 0) {
    toast.value?.warning(
      'Sin datos',
      'No hay datos disponibles para exportar'
    )
    return
  }

  // Prepare data for Excel
  const data = filteredSectorials.value.map(s => ({
    'Nombre': s.name || '',
    'Tipo': s.type || '',
    'Usuario RB': s.user_rb || '',
    'Zona ID': s.zona_id || '',
    'Frecuencia': s.frequency || '',
    'Nodo Torre': s.node_tower || ''
  }))

  // Create worksheet from data
  const worksheet = XLSX.utils.json_to_sheet(data)
  
  // Set column widths for better readability
  worksheet['!cols'] = [
    { wch: 25 }, // Nombre
    { wch: 15 }, // Tipo
    { wch: 15 }, // Usuario
    { wch: 10 }, // Zona
    { wch: 15 }, // Frecuencia
    { wch: 20 }  // Nodo Torre
  ]

  // Create workbook and add worksheet
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Sectoriales')

  // Generate filename with current date
  const date = new Date().toISOString().split('T')[0]
  const filename = `sectorials_excel_${date}.xlsx`

  // Write and download file
  XLSX.writeFile(workbook, filename)
}

onMounted(() => {
    loadRouters()
    loadSectorials()
})
</script>