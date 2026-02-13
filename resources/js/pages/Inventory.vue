<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-xl">
              <v-icon name="bi-box-seam" class="text-purple-600 dark:text-purple-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Inventario
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">Gestiona todos los dispositivos del inventario</p>
        </div>
        
        <button
          @click="$router.push('/inventory/create')"
          class="bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 
                 text-white px-5 py-3 rounded-xl flex items-center justify-center gap-2 
                 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto"
        >
          <v-icon name="md-add" class="w-5 h-5 fill-current" />
          <span>Nuevo Dispositivo</span>
        </button>
      </div>

      <!-- Statistics Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
        <StatCard
          title="Total Dispositivos"
          :value="stats.totalDevices"
          icon="bi-box-seam"
          color="purple"
        />
        <StatCard
          title="En Stock"
          :value="stats.totalStock"
          icon="md-inventory-round"
          color="green"
        />
        <StatCard
          title="Proveedores"
          :value="stats.totalProviders"
          icon="bi-building"
          color="blue"
        />
        <StatCard
          title="Sucursales"
          :value="stats.totalBranches"
          icon="md-storemalldirectory"
          color="orange"
        />
      </div>

      <!-- Filters and Search -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Search -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="md-search" class="w-4 h-4 mr-1" />
              Buscar
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                <v-icon name="md-search" class="w-5 h-5 text-gray-400" />
              </div>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Buscar por serial, MAC, modelo..."
                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 focus:border-transparent
                       transition-all outline-none"
              />
            </div>
          </div>

          <!-- Provider Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="bi-building" class="w-4 h-4 mr-1" />
              Proveedor
            </label>
            <div class="relative">
              <select
                v-model="filters.provider"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 transition-all outline-none appearance-none"
              >
                <option value="">Todos</option>
                <option
                  v-for="provider in providers"
                  :key="provider.id"
                  :value="provider.id"
                >
                  {{ provider.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                <v-icon name="md-keyboardarrowdown" />
              </div>
            </div>
          </div>

          <!-- Branch Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="md-storemalldirectory" class="w-4 h-4 mr-1" />
              Sucursal
            </label>
            <div class="relative">
              <select
                v-model="filters.branch"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-purple-500 transition-all outline-none appearance-none"
              >
                <option value="">Todas</option>
                <option
                  v-for="branch in branches"
                  :key="branch.id"
                  :value="branch.id"
                >
                  {{ branch.name }}
                </option>
              </select>
              <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                <v-icon name="md-keyboardarrowdown" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Desktop Table View -->
      <div class="hidden lg:block bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Dispositivo</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Serial / MAC</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Proveedor</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Sucursal</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Precio</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="device in filteredDevices"
                :key="device.id"
                class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-purple-600 dark:text-purple-400">#{{ device.id }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                      <v-icon name="bi-box-seam" class="w-4 h-4 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-gray-800 dark:text-white">
                        {{ device.stock_brand || 'Sin marca' }} {{ device.stock_model || '' }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400">
                        Stock ID: {{ device.stock_id || 'N/A' }}
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-xs">
                    <div class="text-gray-700 dark:text-gray-300 flex items-center gap-1 font-mono">
                      <v-icon name="bi-upc" class="w-3 h-3" />
                      {{ device.serial || 'N/A' }}
                    </div>
                    <div class="text-gray-500 dark:text-gray-500 mt-1 flex items-center gap-1 font-mono">
                      <v-icon name="md-wifi" class="w-3 h-3" />
                      {{ device.mac || 'N/A' }}
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm text-gray-800 dark:text-gray-200">
                    <v-icon name="bi-building" class="w-3 h-3 mr-1" />
                    {{ device.provider_name || 'Sin proveedor' }}
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm text-gray-800 dark:text-gray-200">
                    <v-icon name="md-storemalldirectory" class="w-3 h-3 mr-1" />
                    {{ device.branch_name || 'Sin sucursal' }}
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-green-600 dark:text-green-400">
                    {{ formatCurrency(device.stock_price) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <button
                      @click="viewDevice(device)"
                      class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Ver detalles"
                    >
                      <v-icon name="md-visibility" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      @click="editDevice(device)"
                      class="p-2 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Editar"
                    >
                      <v-icon name="md-edit" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      @click="deleteDevice(device)"
                      class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Eliminar"
                    >
                      <v-icon name="md-delete" class="w-4 h-4 fill-current" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Empty State -->
          <div
            v-if="filteredDevices.length === 0"
            class="text-center py-16"
          >
            <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
              <v-icon name="bi-box-seam" class="w-10 h-10 text-gray-400" />
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No se encontraron dispositivos</p>
            <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">
              Intenta ajustar los filtros o agrega un nuevo dispositivo
            </p>
            <button
              @click="$router.push('/inventory/create')"
              class="mt-6 px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                     transition-colors text-sm font-medium inline-flex items-center gap-2"
            >
              <v-icon name="md-add" class="w-4 h-4 fill-current" />
              Agregar Primer Dispositivo
            </button>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="filteredDevices.length > 0" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 
                    flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50 dark:bg-gray-900/50">
          <div class="text-sm text-gray-700 dark:text-gray-300">
            Mostrando <span class="font-semibold">{{ paginationStart }}</span> - 
            <span class="font-semibold">{{ paginationEnd }}</span> de 
            <span class="font-semibold">{{ totalDevices }}</span> dispositivos
          </div>
          <div class="flex gap-2">
            <button
              @click="prevPage"
              :disabled="currentPage === 1"
              class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                     disabled:opacity-50 disabled:cursor-not-allowed transition-all
                     flex items-center gap-2"
            >
              <v-icon name="md-chevronleft-round" class="w-4 h-4" />
              <span class="hidden sm:inline">Anterior</span>
            </button>
            <button
              @click="nextPage"
              :disabled="currentPage >= totalPages"
              class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg
                     text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                     disabled:opacity-50 disabled:cursor-not-allowed transition-all
                     flex items-center gap-2"
            >
              <span class="hidden sm:inline">Siguiente</span>
              <v-icon name="md-chevronright" class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Mobile Card View -->
      <div class="lg:hidden space-y-4">
        <div
          v-for="device in filteredDevices"
          :key="device.id"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 hover:shadow-lg transition-all"
        >
          <!-- Header -->
          <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2">
              <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg">
                <v-icon name="bi-box-seam" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
              </div>
              <div>
                <span class="text-xs font-bold text-purple-600 dark:text-purple-400">#{{ device.id }}</span>
                <p class="text-sm font-medium text-gray-800 dark:text-white mt-0.5">
                  {{ device.stock_brand || 'Sin marca' }} {{ device.stock_model || '' }}
                </p>
              </div>
            </div>
            <span class="text-xs font-bold text-green-600 dark:text-green-400">
              {{ formatCurrency(device.stock_price) }}
            </span>
          </div>

          <!-- Info Grid -->
          <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="col-span-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg p-2">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Serial</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 font-mono">
                {{ device.serial || 'N/A' }}
              </p>
            </div>
            <div class="col-span-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg p-2">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">MAC Address</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 font-mono">
                {{ device.mac || 'N/A' }}
              </p>
            </div>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Proveedor</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 truncate">
                {{ device.provider_name || 'N/A' }}
              </p>
            </div>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Sucursal</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 truncate">
                {{ device.branch_name || 'N/A' }}
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div class="grid grid-cols-2 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="viewDevice(device)"
              class="py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-visibility" class="w-4 h-4 fill-current" />
              Ver
            </button>
            <button
              @click="editDevice(device)"
              class="py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-1"
            >
              <v-icon name="md-edit" class="w-4 h-4 fill-current" />
              Editar
            </button>
          </div>
        </div>

        <!-- Mobile Empty State -->
        <div
          v-if="filteredDevices.length === 0"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center"
        >
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="bi-box-seam" class="w-10 h-10 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Sin dispositivos</p>
          <p class="text-gray-400 dark:text-gray-500 text-sm mt-2 mb-6">
            No hay dispositivos en el inventario
          </p>
          <button
            @click="$router.push('/inventory/create')"
            class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg
                   transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <v-icon name="md-add" class="w-4 h-4 fill-current" />
            Agregar Dispositivo
          </button>
        </div>

        <!-- Mobile Pagination -->
        <div v-if="filteredDevices.length > 0" 
             class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 flex flex-col gap-3">
          <div class="text-sm text-center text-gray-700 dark:text-gray-300">
            <span class="font-semibold">{{ paginationStart }}</span> - 
            <span class="font-semibold">{{ paginationEnd }}</span> de 
            <span class="font-semibold">{{ totalDevices }}</span>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <button
              @click="prevPage"
              :disabled="currentPage === 1"
              class="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                     text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                     disabled:opacity-50 disabled:cursor-not-allowed transition-all
                     flex items-center justify-center gap-2 font-medium"
            >
              <v-icon name="md-chevronleft-round" class="w-4 h-4" />
              Anterior
            </button>
            <button
              @click="nextPage"
              :disabled="currentPage >= totalPages"
              class="px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                     text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                     disabled:opacity-50 disabled:cursor-not-allowed transition-all
                     flex items-center justify-center gap-2 font-medium"
            >
              Siguiente
              <v-icon name="md-chevronright" class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
        @click.self="closeDeleteModal"
      >
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <v-icon name="md-delete" class="w-6 h-6 text-red-600" />
                Eliminar Dispositivo
              </h2>
            </div>
            <button
              @click="closeDeleteModal"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
              <v-icon name="md-close" class="w-6 h-6" />
            </button>
          </div>

          <!-- Content -->
          <div class="space-y-4">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <div class="flex items-start gap-3">
                <v-icon name="md-warning-round" class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                <div>
                  <h4 class="font-medium text-red-800 dark:text-red-300">¿Estás seguro?</h4>
                  <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    Esta acción no se puede deshacer. El dispositivo <strong>"#{{ deviceToDelete?.id }} - {{ deviceToDelete?.stock_brand }} {{ deviceToDelete?.stock_model }}"</strong> será eliminado permanentemente.
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
              :disabled="deleting"
            >
              Cancelar
            </button>
            <button
              @click="confirmDeleteDevice"
              :disabled="deleting"
              class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <v-icon v-if="deleting" name="ri-loader-4-line" animation="spin" class="w-4 h-4" />
              <v-icon v-else name="md-delete" class="w-4 h-4" />
              {{ deleting ? 'Eliminando...' : 'Eliminar' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Notification Toast -->
      <NotificationToast ref="toast" />

    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import StatCard from '@/components/StatCard.vue'
import NotificationToast from '@/components/NotificationToast.vue'

// State
const devices = ref([])
const providers = ref([])
const branches = ref([])
const loading = ref(false)
const toast = ref(null)

// Delete modal state
const showDeleteModal = ref(false)
const deviceToDelete = ref(null)
const deleting = ref(false)
const tenantId = ref(null)

// Get tenant_id from logged-in user
const getUserTenantId = () => {
  const userData = JSON.parse(localStorage.getItem('userData')) ?? JSON.parse(sessionStorage.getItem('userData'))
  if (!userData?.tenant_id) {
    console.error('⚠️ No se encontró tenant_id del usuario autenticado.')
    return null
  }
  return userData.tenant_id
}

// Filters
const filters = ref({
  search: '',
  provider: '',
  branch: ''
})

// Pagination
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Stats
const stats = ref({
  totalDevices: 0,
  totalStock: 0,
  totalProviders: 0,
  totalBranches: 0
})

// Computed
const filteredDevices = computed(() => {
  let result = devices.value

  // Search filter
  if (filters.value.search) {
    const search = filters.value.search.toLowerCase()
    result = result.filter(d => 
      (d.serial?.toLowerCase().includes(search)) ||
      (d.mac?.toLowerCase().includes(search)) ||
      (d.stock_model?.toLowerCase().includes(search)) ||
      (d.stock_brand?.toLowerCase().includes(search)) ||
      (d.id?.toString().includes(search))
    )
  }

  // Provider filter
  if (filters.value.provider) {
    result = result.filter(d => d.provider_id == filters.value.provider)
  }

  // Branch filter
  if (filters.value.branch) {
    result = result.filter(d => d.branch_id == filters.value.branch)
  }

  // Pagination
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return result.slice(start, end)
})

const totalDevices = computed(() => devices.value.length)
const totalPages = computed(() => Math.ceil(totalDevices.value / itemsPerPage.value))
const paginationStart = computed(() => (currentPage.value - 1) * itemsPerPage.value + 1)
const paginationEnd = computed(() => Math.min(currentPage.value * itemsPerPage.value, totalDevices.value))

// Methods
const loadDevices = async () => {
  loading.value = true
  try {
    if (!tenantId.value) return

    const { data, error } = await supabase
      .from('inventory_device')
      .select(`
        *,
        stock:stock_id (
          id,
          brand,
          model,
          price
        ),
        provider:provider_id (
          id,
          name
        ),
        branch:branch_id (
          id,
          name
        )
      `)
      .eq('tenant_id', tenantId.value)
      .order('created_at', { ascending: false })

    if (error) throw error
    
    devices.value = data.map(device => ({
      ...device,
      stock_brand: device.stock?.brand || null,
      stock_model: device.stock?.model || null,
      stock_price: device.stock?.price || 0,
      provider_name: device.provider?.name || null,
      branch_name: device.branch?.name || null
    }))

    calculateStats()
  } catch (error) {
    console.error('Error loading devices:', error)
    toast.value?.error('Error', 'Error al cargar los dispositivos')
  } finally {
    loading.value = false
  }
}

const loadProviders = async () => {
  try {
    const { data, error } = await supabase
      .from('inventory_provider')
      .select('id, name')
      .eq('tenant_id', tenantId.value)
      .order('name')

    if (error) throw error
    providers.value = data || []
  } catch (error) {
    console.error('Error loading providers:', error)
  }
}

const loadBranches = async () => {
  try {
    const { data, error } = await supabase
      .from('inventory_branch')
      .select('id, name')
      .eq('tenant_id', tenantId.value)
      .order('name')

    if (error) throw error
    branches.value = data || []
  } catch (error) {
    console.error('Error loading branches:', error)
  }
}

const calculateStats = () => {
  stats.value.totalDevices = devices.value.length
  stats.value.totalStock = devices.value.filter(d => d.stock_id).length
  stats.value.totalProviders = new Set(devices.value.map(d => d.provider_id).filter(Boolean)).size
  stats.value.totalBranches = new Set(devices.value.map(d => d.branch_id).filter(Boolean)).size
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
}

const viewDevice = (device) => {
  // TODO: Implement view modal
  console.log('View device:', device)
  toast.value?.info('Información del Dispositivo', `Serial: ${device.serial}\nMAC: ${device.mac}`)
}

const editDevice = (device) => {
  window.location.href = `/inventory/${device.id}/edit`
}

const deleteDevice = (device) => {
  deviceToDelete.value = device
  showDeleteModal.value = true
}

const closeDeleteModal = () => {
  showDeleteModal.value = false
  deviceToDelete.value = null
}

const confirmDeleteDevice = async () => {
  if (!deviceToDelete.value) return
  deleting.value = true

  try {
    const { error } = await supabase
      .from('inventory_device')
      .delete()
      .eq('id', deviceToDelete.value.id)
      .eq('tenant_id', tenantId.value) // Ensure tenant_id is used for deletion

    if (error) throw error

    toast.value?.success('Eliminado', 'Dispositivo eliminado correctamente')
    closeDeleteModal()
    await loadDevices()
  } catch (error) {
    console.error('Error deleting device:', error)
    toast.value?.error('Error', 'No se pudo eliminar el dispositivo')
  } finally {
    deleting.value = false
  }
}

const prevPage = () => {
  if (currentPage.value > 1) currentPage.value--
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) currentPage.value++
}

// Lifecycle
onMounted(async () => {
  tenantId.value = getUserTenantId()
  await Promise.all([
    loadDevices(),
    loadProviders(),
    loadBranches()
  ])
})
</script>

<style scoped>
/* Additional custom styles if needed */
</style>
