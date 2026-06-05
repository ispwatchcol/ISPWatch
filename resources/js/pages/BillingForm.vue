<template>
  <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
    <main class="flex-1 p-4 md:p-8">
      
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
        <div>
          <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2">
            <div class="p-2 bg-green-100 dark:bg-green-900/30 rounded-xl">
              <v-icon name="la-file-invoice-dollar-solid" class="text-green-600 dark:text-green-400 w-6 h-6 md:w-7 md:h-7" />
            </div>
            {{ isEdit ? 'Editar Factura' : 'Nueva Factura' }}
          </h1>
          <p class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1">
            {{ isEdit ? 'Actualiza la información de la factura' : 'Completa el formulario para crear una nueva factura' }}
          </p>
        </div>
        
        <button
          @click="goBack"
          class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 
                 px-4 py-2.5 rounded-xl flex items-center gap-2 hover:bg-gray-300 
                 dark:hover:bg-gray-600 transition-all shadow-md w-full sm:w-auto justify-center"
        >
          <v-icon name="md-arrowback" class="w-4 h-4" />
          Volver
        </button>
      </div>

      <!-- Form Card -->
      <div class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden max-w-5xl mx-auto">
        
        <!-- Progress Indicator -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-6 text-white">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium opacity-90">Formulario de Factura</span>
            <span class="text-xs opacity-75">* Campos obligatorios</span>
          </div>
          <div class="h-1 bg-blue-500/30 rounded-full overflow-hidden">
            <div class="h-full bg-white rounded-full" style="width: 60%"></div>
          </div>
        </div>

        <form @submit.prevent="handleSubmit" class="p-6 md:p-8">
          
          <!-- Section: Información Básica -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                <span class="text-blue-600 dark:text-blue-400 font-bold text-sm">1</span>
              </div>
              Información Básica
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Router Selection -->
              <div class="md:col-span-2">
                <label class="label">
                  <v-icon name="bi-router" class="w-4 h-4 mr-1 inline" />
                  Router / Cliente *
                </label>
                <SearchableSelect
                  v-model="form.router_id"
                  :items="routers"
                  item-key="id"
                  :item-label="routerLabel"
                  item-icon="bi-router"
                  placeholder="Selecciona un router..."
                  search-placeholder="Buscar router o IP..."
                  :required="true"
                />
                <p class="hint">Selecciona el router asociado a esta factura</p>
              </div>

              <!-- Amount -->
              <div>
                <label class="label">
                  <v-icon name="md-attachmoney" class="w-4 h-4 mr-1 inline" />
                  Monto *
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                     <span class="text-gray-500 dark:text-gray-400 font-bold text-lg">$</span>
                  </div>
                  <input
                    v-model.number="form.amount"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    placeholder="25,000"
                    class="input pl-10 text-lg font-semibold"
                    :disabled="loading"
                  />
                </div>
                <p class="hint">Valor total de la factura en pesos colombianos</p>
              </div>

              <!-- Billing Type -->
              <div>
                <label class="label">
                  <v-icon name="bi-tag" class="w-4 h-4 mr-1 inline" />
                  Tipo de Facturación *
                </label>
                <select
                  v-model="form.id_type"
                  required
                  class="input"
                  :disabled="loading"
                >
                  <option value="">Selecciona un tipo...</option>
                  <option
                    v-for="type in billingTypes"
                    :key="type.id"
                    :value="type.id"
                  >
                    {{ type.type }}
                  </option>
                </select>
                <p class="hint">Método de facturación aplicable</p>
              </div>
            </div>
          </div>

          <!-- Section: Fechas -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                <span class="text-purple-600 dark:text-purple-400 font-bold text-sm">2</span>
              </div>
              Fechas Importantes
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Invoice Date -->
              <div>
                <label class="label">
                  <v-icon name="bi-calendar-plus" class="w-4 h-4 mr-1 inline" />
                  Fecha de Emisión *
                </label>
                <input
                  v-model="form.create_invoice"
                  type="date"
                  required
                  class="input"
                  :disabled="loading"
                />
                <input
                  v-model="form.create_invoice_time"
                  type="time"
                  class="input mt-2"
                  :disabled="loading"
                />
                <p class="hint">Día y hora en que se genera la factura</p>
              </div>

              <!-- Payment Day -->
              <div>
                <label class="label">
                  <v-icon name="bi-calendar-event" class="w-4 h-4 mr-1 inline" />
                  Fecha de Vencimiento *
                </label>
                <input
                  v-model="form.payment_day"
                  type="date"
                  required
                  class="input"
                  :disabled="loading"
                />
                <p class="hint">Fecha límite para realizar el pago</p>
              </div>

              <!-- Cut Day -->
              <div>
                <label class="label">
                  <v-icon name="bi-calendar-x" class="w-4 h-4 mr-1 inline" />
                  Fecha de Corte
                </label>
                <input
                  v-model="form.cut_day"
                  type="date"
                  class="input"
                  :disabled="loading"
                />
                <input
                  v-model="form.cut_time"
                  type="time"
                  class="input mt-2"
                  :disabled="loading"
                />
                <p class="hint">Día y hora en que se suspenderá el servicio (opcional)</p>
              </div>

              <!-- Payment Reminder -->
              <div>
                <label class="label">
                  <v-icon name="bi-bell" class="w-4 h-4 mr-1 inline" />
                  Recordatorio de Pago
                </label>
                <input
                  v-model="form.payment_reminder"
                  type="date"
                  class="input"
                  :disabled="loading"
                />
                <input
                  v-model="form.payment_reminder_time"
                  type="time"
                  class="input mt-2"
                  :disabled="loading"
                />
                <p class="hint">Día y hora para enviar recordatorio (opcional)</p>
              </div>
            </div>
          </div>

          <!-- Section: Configuración Adicional -->
          <div class="mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
              <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                <span class="text-orange-600 dark:text-orange-400 font-bold text-sm">3</span>
              </div>
              Configuración Adicional
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Overdue Invoices -->
              <div>
                <label class="label">
                  <v-icon name="bi-exclamation-triangle" class="w-4 h-4 mr-1 inline" />
                  Facturas Vencidas Permitidas
                </label>
                <input
                  v-model.number="form.overdue_invoices"
                  type="number"
                  min="0"
                  max="10"
                  placeholder="0"
                  class="input"
                  :disabled="loading"
                />
                <p class="hint">Número de facturas vencidas antes de suspender el servicio</p>
              </div>

              <!-- Status -->
              <div>
                <label class="label">
                  <v-icon name="bi-activity" class="w-4 h-4 mr-1 inline" />
                  Estado de la Factura *
                </label>
                <div class="relative">
                    <select
                    v-model="form.status"
                    required
                    class="input appearance-none"
                    :disabled="loading"
                    >
                    <option value="pending">⏳ Pendiente</option>
                    <option value="paid">✅ Pagado</option>
                    <option value="overdue">⚠️ Vencido</option>
                    <option value="cancelled">❌ Cancelado</option>
                    </select>
                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500">
                         <v-icon name="md-keyboardarrowdown" />
                    </div>
                </div>
                <p class="hint">Estado actual de esta factura</p>
              </div>
            </div>
          </div>

          <!-- Form Summary Card -->
          <div v-if="form.amount && form.router_id" 
               class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 
                      rounded-xl p-6 mb-8 border border-blue-200 dark:border-blue-800">
            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
              <v-icon name="bi-info-circle" class="w-4 h-4" />
              Resumen de la Factura
            </h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Monto Total</p>
                <p class="text-xl font-bold text-blue-600 dark:text-blue-400 mt-1">
                  {{ formatCurrency(form.amount) }}
                </p>
              </div>
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Estado</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-1">
                  {{ getStatusLabel(form.status) }}
                </p>
              </div>
              <div>
                <p class="text-xs text-gray-600 dark:text-gray-400">Vencimiento</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white mt-1">
                  {{ form.payment_day || 'No definido' }}
                </p>
              </div>
            </div>
          </div>

          <!-- Submit Buttons -->
          <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-gray-200 dark:border-gray-700">
            <button
              type="button"
              @click="goBack"
              class="flex-1 py-3 px-6 border-2 border-gray-300 dark:border-gray-600 rounded-xl
                     text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700
                     transition-all font-medium flex items-center justify-center gap-2"
              :disabled="loading"
            >
              <v-icon name="md-close" class="w-5 h-5" />
              Cancelar
            </button>
            <button
              type="submit"
              class="flex-1 py-3 px-6 bg-gradient-to-r from-blue-600 to-blue-700 
                     hover:from-blue-700 hover:to-blue-800 text-white rounded-xl
                     transition-all font-medium shadow-lg hover:shadow-xl
                     transform hover:-translate-y-0.5
                     disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none
                     flex items-center justify-center gap-2"
              :disabled="loading"
            >
              <v-icon v-if="loading" name="bi-arrow-clockwise" animation="spin" class="w-5 h-5" />
              <v-icon v-else name="md-check" class="w-5 h-5" />
              {{ loading ? 'Guardando...' : (isEdit ? 'Actualizar Factura' : 'Crear Factura') }}
            </button>
          </div>

        </form>
      </div>

    </main>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { supabase } from '@/supabase.js'
import SearchableSelect from '@/components/SearchableSelect.vue'

const router = useRouter()
const route = useRoute()
const billingId = route.params.id
const isEdit = !!billingId

// State
const loading = ref(false)
const routers = ref([])
const billingTypes = ref([])

// Form
const form = ref({
  router_id: null,
  id_type: null,
  amount: null,
  create_invoice: new Date().toISOString().split('T')[0],
  create_invoice_time: '00:00',
  payment_day: null,
  cut_day: null,
  cut_time: '00:00',
  payment_reminder: null,
  payment_reminder_time: '00:00',
  overdue_invoices: 0,
  status: 'pending'
})

// Methods
const routerLabel = (r) => `${r.name} - ${r.ip || 'Sin IP'}`

// Hora "HH:MM:SS" (BD) → "HH:MM" (input) y viceversa. Vacío → medianoche, que
// conserva el comportamiento por fecha del sistema.
const sqlToTime = (val) => (val && typeof val === 'string') ? val.slice(0, 5) : '00:00'
const toSqlTime = (val) => {
  if (!val || typeof val !== 'string') return '00:00:00'
  const [h = '0', m = '0', s = '0'] = val.split(':')
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
}

const loadRouters = async () => {
  try {
    const { data, error } = await supabase
      .from('router')
      .select('id, name, ip')
      .eq('status', 'active')
      .order('name')

    if (error) throw error
    routers.value = data || []
  } catch (error) {
    console.error('Error loading routers:', error)
    alert('Error al cargar los routers')
  }
}

const loadBillingTypes = async () => {
  try {
    const { data, error } = await supabase
      .from('type_billing')
      .select('id, type')
      .order('type')

    if (error) throw error
    billingTypes.value = data || []
  } catch (error) {
    console.error('Error loading billing types:', error)
    alert('Error al cargar los tipos de facturación')
  }
}

const loadBilling = async () => {
  if (!isEdit) return

  loading.value = true
  try {
    const { data, error } = await supabase
      .from('billing')
      .select('*')
      .eq('id', billingId)
      .single()

    if (error) throw error

    form.value = {
      router_id: data.billing_router_id || null,
      id_type: data.id_type || null,
      amount: data.amount || null,
      create_invoice: data.create_invoice || new Date().toISOString().split('T')[0],
      create_invoice_time: sqlToTime(data.create_invoice_time),
      payment_day: data.payment_day || null,
      cut_day: data.cut_day || null,
      cut_time: sqlToTime(data.cut_time),
      payment_reminder: data.payment_reminder || null,
      payment_reminder_time: sqlToTime(data.payment_reminder_time),
      overdue_invoices: data.overdue_invoices || 0,
      status: data.status || 'pending'
    }
  } catch (error) {
    console.error('Error loading billing:', error)
    alert('Error al cargar la factura')
    router.push('/billing')
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  loading.value = true
  try {
    const payload = {
      billing_router_id: form.value.router_id,
      id_type: form.value.id_type,
      amount: form.value.amount,
      create_invoice: form.value.create_invoice,
      create_invoice_time: toSqlTime(form.value.create_invoice_time),
      payment_day: form.value.payment_day,
      cut_day: form.value.cut_day || null,
      cut_time: toSqlTime(form.value.cut_time),
      payment_reminder: form.value.payment_reminder || null,
      payment_reminder_time: toSqlTime(form.value.payment_reminder_time),
      overdue_invoices: form.value.overdue_invoices || 0,
      status: form.value.status
    }

    if (isEdit) {
      // Update
      const { error } = await supabase
        .from('billing')
        .update(payload)
        .eq('id', billingId)

      if (error) throw error
      alert('✅ Factura actualizada correctamente')
    } else {
      // Create
      const { error } = await supabase
        .from('billing')
        .insert(payload)

      if (error) throw error
      alert('✅ Factura creada correctamente')
    }

    router.push('/billing')
  } catch (error) {
    console.error('Error saving billing:', error)
    alert('❌ Error al guardar la factura: ' + error.message)
  } finally {
    loading.value = false
  }
}

const goBack = () => {
  router.push('/billing')
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0
  }).format(value || 0)
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

// Lifecycle
onMounted(async () => {
  await loadRouters()
  await loadBillingTypes()
  await loadBilling()
})
</script>

<style scoped>
.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
  @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
         focus:ring-2 focus:ring-blue-500 focus:border-transparent
         disabled:opacity-50 disabled:cursor-not-allowed transition-all
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
.hint {
  @apply mt-2 text-xs text-gray-500 dark:text-gray-400 flex items-start gap-1;
}
.hint::before {
  content: '💡';
  flex-shrink: 0;
}
</style>
