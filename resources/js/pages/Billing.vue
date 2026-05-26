<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-green-100 dark:bg-green-900/30 rounded-xl">
              <v-icon name="la-money-bill-wave-solid" class="text-green-600 dark:text-green-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            Facturación
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">Gestiona todas las facturas de tus clientes</p>
        </div>
        
        <button
          @click="$router.push('/billing/create')"
          class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 
                 text-white px-5 py-3 rounded-xl flex items-center justify-center gap-2 
                 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5
                 font-medium w-full sm:w-auto"
        >
          <v-icon name="md-add" class="w-5 h-5 fill-current" />
          <span>Nueva Factura</span>
        </button>
      </div>

      <!-- Statistics Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
        <StatCard
          title="Total Facturado"
          :value="formatCurrency(stats.totalBilled)"
          icon="la-dollar-sign-solid"
          color="green"
          trend="+12.5%"
        />
        <StatCard
          title="Facturas Pendientes"
          :value="stats.pendingCount"
          icon="md-pending-twotone"
          color="yellow"
        />
        <StatCard
          title="Facturas Vencidas"
          :value="stats.overdueCount"
          icon="md-warning-round"
          color="red"
          trend="-5%"
        />
        <StatCard
          title="Cobrado Este Mes"
          :value="formatCurrency(stats.thisMonth)"
          icon="md-trendingup-round"
          color="blue"
          trend="+8.2%"
        />
      </div>

      <!-- Filters and Search -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 md:p-6 mb-6 text-gray-700 dark:text-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Search -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="md-search" class="w-4 h-4 mr-1" />
              Buscar
            </label>
            <div class="relative text-gray-600 dark:text-gray-400">
              <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                 <v-icon name="md-search" class="w-5 h-5" />
              </div>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Buscar por cliente, router, ID..."
                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 
                       rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent
                       transition-all outline-none"
              />
            </div>
          </div>

          <!-- Status Filter -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="bi-filter" class="w-4 h-4 mr-1" />
              Estado
            </label>
            <div class="relative">
                <select
                v-model="filters.status"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                        rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                        focus:ring-2 focus:ring-blue-500 transition-all outline-none appearance-none"
                >
                <option value="">Todos</option>
                <option value="pending">Pendiente</option>
                <option value="paid">Pagado</option>
                <option value="overdue">Vencido</option>
                <option value="cancelled">Cancelado</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="hi-chevron-down" />
                </div>
            </div>
          </div>

          <!-- Date Range -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center">
              <v-icon name="bi-calendar" class="w-4 h-4 mr-1" />
              Período
            </label>
            <div class="relative">
                <select
                v-model="filters.period"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 
                        rounded-xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100
                        focus:ring-2 focus:ring-blue-500 transition-all outline-none appearance-none"
                >
                <option value="all">Todos</option>
                <option value="today">Hoy</option>
                <option value="week">Esta Semana</option>
                <option value="month">Este Mes</option>
                <option value="year">Este Año</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                    <v-icon name="hi-chevron-down" />
                </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Desktop Table View -->
      <div class="hidden md:block bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-100 dark:bg-gray-700">
              <tr>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Cliente / Router</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Monto</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Fechas</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Estado</th>
                <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
              <tr
                v-for="bill in filteredBillings"
                :key="bill.id"
                class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
              >
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-sm font-bold text-blue-600 dark:text-blue-400">#{{ bill.id }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                      <v-icon name="bi-person" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-gray-800 dark:text-white">
                        {{ bill.customer_name || 'N/A' }}
                      </div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                        <v-icon name="bi-router" class="w-3 h-3" />
                        {{ bill.router_name || 'Sin router' }}
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="text-base font-bold text-gray-800 dark:text-white">
                    {{ formatCurrency(bill.amount) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-xs">
                    <div class="text-gray-700 dark:text-gray-300 flex items-center gap-1">
                      <v-icon name="bi-calendar-plus" class="w-3 h-3" />
                      {{ formatDate(bill.create_invoice) }}
                    </div>
                    <div class="text-gray-500 dark:text-gray-500 mt-1 flex items-center gap-1">
                       <v-icon name="bi-calendar-event" class="w-3 h-3" />
                      Vence: {{ formatDate(bill.payment_day) }}
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    :class="getStatusBadgeClass(bill.status)"
                    class="px-3 py-1.5 text-xs font-semibold rounded-full inline-flex items-center gap-1"
                  >
                    <span class="w-2 h-2 rounded-full" :class="getStatusDotClass(bill.status)"></span>
                    {{ getStatusLabel(bill.status) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <button
                      @click="editBilling(bill)"
                      class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Editar"
                    >
                      <v-icon name="md-edit" class="w-4 h-4 fill-current" />
                    </button>
                    <button
                      v-if="bill.status === 'pending'"
                      @click="markAsPaid(bill)"
                      class="p-2 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30 
                             rounded-lg transition-all hover:scale-110"
                      title="Marcar como pagado"
                    >
                      <v-icon name="md-checkcircle" class="w-4 h-4 fill-current" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Empty State -->
          <div
            v-if="filteredBillings.length === 0"
            class="text-center py-16"
          >
            <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
              <v-icon name="la-file-invoice-dollar-solid" class="w-10 h-10 text-gray-400" />
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No se encontraron facturas</p>
            <p class="text-gray-400 dark:text-gray-500 text-sm mt-2">
              Intenta ajustar los filtros o crea una nueva factura
            </p>
            <button
              @click="$router.push('/billing/create')"
              class="mt-6 px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                     transition-colors text-sm font-medium inline-flex items-center gap-2"
            >
              <v-icon name="md-add" class="w-4 h-4 fill-current" />
              Crear Primera Factura
            </button>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="filteredBillings.length > 0" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 
                    flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50 dark:bg-gray-900/50">
          <div class="text-sm text-gray-700 dark:text-gray-300">
            Mostrando <span class="font-semibold">{{ paginationStart }}</span> - 
            <span class="font-semibold">{{ paginationEnd }}</span> de 
            <span class="font-semibold">{{ totalBillings }}</span> facturas
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
              <v-icon name="md-chevronleft" class="w-4 h-4" />
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
      <div class="md:hidden space-y-4">
        <div
          v-for="bill in filteredBillings"
          :key="bill.id"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 hover:shadow-lg transition-all"
        >
          <!-- Header -->
          <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2">
              <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg">
                <v-icon name="la-file-invoice-dollar-solid" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
              </div>
              <div>
                <span class="text-xs font-bold text-blue-600 dark:text-blue-400">#{{ bill.id }}</span>
                <p class="text-sm font-medium text-gray-800 dark:text-white mt-0.5">
                  {{ bill.customer_name || 'N/A' }}
                </p>
              </div>
            </div>
            <span
              :class="getStatusBadgeClass(bill.status)"
              class="px-2.5 py-1 text-xs font-semibold rounded-full"
            >
              {{ getStatusLabel(bill.status) }}
            </span>
          </div>

          <!-- Amount -->
          <div class="bg-gray-50 dark:bg-gray-700/50 
                      rounded-lg p-3 mb-3 border border-gray-100 dark:border-gray-700">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Monto</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">
              {{ formatCurrency(bill.amount) }}
            </p>
          </div>

          <!-- Info Grid -->
          <div class="grid grid-cols-2 gap-3 mb-3">
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Router</p>
              <p class="text-sm text-gray-800 dark:text-gray-200 font-medium truncate flex items-center gap-1">
                <v-icon name="bi-router" class="w-3 h-3" />
                {{ bill.router_name || 'Sin router' }}
              </p>
            </div>
            <div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Emisión</p>
              <p class="text-sm text-gray-800 dark:text-gray-200">
                {{ formatDate(bill.create_invoice) }}
              </p>
            </div>
            <div class="col-span-2">
              <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Vencimiento</p>
              <p class="text-sm text-gray-800 dark:text-gray-200">
                {{ formatDate(bill.payment_day) }}
              </p>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="editBilling(bill)"
              class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-2"
            >
              <v-icon name="md-edit" class="w-4 h-4 fill-current" />
              Editar
            </button>
            <button
              v-if="bill.status === 'pending'"
              @click="markAsPaid(bill)"
              class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg
                     transition-colors text-sm font-medium flex items-center justify-center gap-2"
            >
              <v-icon name="md-checkcircle" class="w-4 h-4 fill-current" />
              Pagado
            </button>
          </div>
        </div>

        <!-- Mobile Empty State -->
        <div
          v-if="filteredBillings.length === 0"
          class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-8 text-center"
        >
          <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-full w-20 h-20 mx-auto mb-4 flex items-center justify-center">
            <v-icon name="la-file-invoice-dollar-solid" class="w-10 h-10 text-gray-400" />
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No hay facturas</p>
          <p class="text-gray-400 dark:text-gray-500 text-sm mt-2 mb-6">
            Intenta ajustar los filtros
          </p>
          <button
            @click="$router.push('/billing/create')"
            class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                   transition-colors text-sm font-medium flex items-center justify-center gap-2"
          >
            <v-icon name="md-add" class="w-4 h-4 fill-current" />
            Crear Factura
          </button>
        </div>

        <!-- Mobile Pagination -->
        <div v-if="filteredBillings.length > 0" 
             class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-4 flex flex-col gap-3">
          <div class="text-sm text-center text-gray-700 dark:text-gray-300">
            <span class="font-semibold">{{ paginationStart }}</span> - 
            <span class="font-semibold">{{ paginationEnd }}</span> de 
            <span class="font-semibold">{{ totalBillings }}</span>
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
              <v-icon name="md-chevronleft" class="w-4 h-4" />
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

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '@/supabase.js'
import StatCard from '@/components/StatCard.vue'

// State
const billings = ref([])
const loading = ref(false)

// Filters
const filters = ref({
  search: '',
  status: '',
  period: 'month'
})

// Pagination
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Stats
const stats = ref({
  totalBilled: 0,
  pendingCount: 0,
  overdueCount: 0,
  thisMonth: 0
})

// Computed
const filteredBillings = computed(() => {
  let result = billings.value

  // Search filter
  if (filters.value.search) {
    const search = filters.value.search.toLowerCase()
    result = result.filter(b => 
      (b.customer_name?.toLowerCase().includes(search)) ||
      (b.router_name?.toLowerCase().includes(search)) ||
      (b.id?.toString().includes(search))
    )
  }

  // Status filter
  if (filters.value.status) {
    result = result.filter(b => b.status === filters.value.status)
  }

  // Period filter
  if (filters.value.period && filters.value.period !== 'all') {
    const now = new Date()
    result = result.filter(b => {
      const date = new Date(b.create_invoice)
      
      switch (filters.value.period) {
        case 'today':
          return date.toDateString() === now.toDateString()
        case 'week':
          const weekAgo = new Date(now.setDate(now.getDate() - 7))
          return date >= weekAgo
        case 'month':
          return date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear()
        case 'year':
          return date.getFullYear() === now.getFullYear()
        default:
          return true
      }
    })
  }

  // Pagination
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return result.slice(start, end)
})

const totalBillings = computed(() => billings.value.length)
const totalPages = computed(() => Math.ceil(totalBillings.value / itemsPerPage.value))
const paginationStart = computed(() => (currentPage.value - 1) * itemsPerPage.value + 1)
const paginationEnd = computed(() => Math.min(currentPage.value * itemsPerPage.value, totalBillings.value))

// Methods
const loadBillings = async () => {
  loading.value = true
  try {
    const { data, error } = await supabase
      .from('billing')
      .select(`
        *,
        router:billing_router_id (
          id,
          name
        )
      `)
      .order('created_at', { ascending: false })

    if (error) throw error
    
    billings.value = data.map(bill => ({
      ...bill,
      router_name: bill.router?.name || 'N/A',
      customer_name: 'Cliente ' + (bill.id || 'N/A') // TODO: Join with customer table
    }))

    calculateStats()
  } catch (error) {
    console.error('Error loading billings:', error)
    alert('Error al cargar las facturas')
  } finally {
    loading.value = false
  }
}

const calculateStats = () => {
  stats.value.totalBilled = billings.value.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0)
  stats.value.pendingCount = billings.value.filter(b => b.status === 'pending').length
  stats.value.overdueCount = billings.value.filter(b => b.status === 'overdue').length
  
  const now = new Date()
  stats.value.thisMonth = billings.value
    .filter(b => {
      const date = new Date(b.create_invoice)
      return date.getMonth() === now.getMonth() && 
             date.getFullYear() === now.getFullYear() &&
             b.status === 'paid'
    })
    .reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0)
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('es-CO', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  })
}

const getStatusLabel = (status) => {
  const labels = {
    pending: 'Pendiente',
    paid: 'Pagado',
    overdue: 'Vencido',
    cancelled: 'Cancelado'
  }
  return labels[status] || status
}

const getStatusBadgeClass = (status) => {
  const classes = {
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    paid: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
    overdue: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400'
  }
  return classes[status] || classes.pending
}

const getStatusDotClass = (status) => {
  const classes = {
    pending: 'bg-yellow-500 animate-pulse',
    paid: 'bg-green-500',
    overdue: 'bg-red-500 animate-pulse',
    cancelled: 'bg-gray-500'
  }
  return classes[status] || classes.pending
}

const editBilling = (bill) => {
  // Navigate to edit page
  window.location.href = `/billing/${bill.id}/edit`
}

const markAsPaid = async (bill) => {
  if (!confirm('¿Marcar esta factura como pagada?')) return

  try {
    const { error } = await supabase
      .from('billing')
      .update({ status: 'paid' })
      .eq('id', bill.id)

    if (error) throw error

    alert('Factura marcada como pagada')
    await loadBillings()
  } catch (error) {
    console.error('Error updating billing:', error)
    alert('Error al actualizar la factura')
  }
}

const prevPage = () => {
  if (currentPage.value > 1) currentPage.value--
}

const nextPage = () => {
  if (currentPage.value < totalPages.value) currentPage.value++
}

// Lifecycle
onMounted(() => {
  loadBillings()
})
</script>

<style scoped>
/* Additional custom styles if needed */
</style>
