<template>
  <Transition name="modal">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
      @click.self="closeModal"
    >
      <div
        class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden"
        @click.stop
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <div class="flex items-center gap-3">
            <div class="p-2 bg-green-100 dark:bg-green-900/30 rounded-lg">
              <v-icon name="la-file-invoice-dollar-solid" class="w-6 h-6 text-green-600 dark:text-green-400" />
            </div>
            <div>
              <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ isEdit ? 'Editar Factura' : 'Nueva Factura' }}
              </h2>
              <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ isEdit ? 'Actualiza la información de la factura' : 'Crea una nueva factura para un cliente' }}
              </p>
            </div>
          </div>
          <button
            @click="closeModal"
            class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 
                   hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
          >
            <icon-lucide-x class="w-5 h-5" />
          </button>
        </div>

        <!-- Form -->
        <form @submit.prevent="handleSubmit" class="p-6 overflow-y-auto max-h-[calc(90vh-140px)]">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Router Selection -->
            <div class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Router / Cliente *
              </label>
              <select
                v-model="form.router_id"
                required
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent
                       disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="loading"
              >
                <option value="">Selecciona un router...</option>
                <option
                  v-for="router in routers"
                  :key="router.id"
                  :value="router.id"
                >
                  {{ router.name }} - {{ router.ip || 'Sin IP' }}
                </option>
              </select>
            </div>

            <!-- Amount -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Monto *
              </label>
              <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                  $
                </span>
                <input
                  v-model.number="form.amount"
                  type="number"
                  step="0.01"
                  min="0"
                  required
                  placeholder="25000"
                  class="w-full pl-8 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                         focus:ring-2 focus:ring-blue-500 focus:border-transparent
                         disabled:opacity-50"
                  :disabled="loading"
                />
              </div>
            </div>

            <!-- Billing Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Tipo de Facturación *
              </label>
              <select
                v-model="form.id_type"
                required
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
            </div>

            <!-- Invoice Date -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Fecha de Emisión *
              </label>
              <input
                v-model="form.create_invoice"
                type="date"
                required
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              />
            </div>

            <!-- Payment Day -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Fecha de Vencimiento *
              </label>
              <input
                v-model="form.payment_day"
                type="date"
                required
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              />
            </div>

            <!-- Cut Day -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Fecha de Corte
              </label>
              <input
                v-model="form.cut_day"
                type="date"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              />
            </div>

            <!-- Payment Reminder -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Recordatorio de Pago
              </label>
              <input
                v-model="form.payment_reminder"
                type="date"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              />
            </div>

            <!-- Overdue Invoices -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Facturas Vencidas Permitidas
              </label>
              <input
                v-model.number="form.overdue_invoices"
                type="number"
                min="0"
                placeholder="0"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Número de facturas vencidas antes de suspender
              </p>
            </div>

            <!-- Status -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Estado *
              </label>
              <select
                v-model="form.status"
                required
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                       bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :disabled="loading"
              >
                <option value="pending">Pendiente</option>
                <option value="paid">Pagado</option>
                <option value="overdue">Vencido</option>
                <option value="cancelled">Cancelado</option>
              </select>
            </div>

          </div>
        </form>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
          <button
            type="button"
            @click="closeModal"
            class="px-5 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg
                   text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                   transition-colors font-medium"
            :disabled="loading"
          >
            Cancelar
          </button>
          <button
            @click="handleSubmit"
            class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg
                   transition-colors font-medium flex items-center gap-2
                   disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="loading"
          >
            <icon-lucide-loader-2 v-if="loading" class="w-4 h-4 animate-spin" />
            <icon-lucide-check v-else class="w-4 h-4" />
            {{ loading ? 'Guardando...' : (isEdit ? 'Actualizar' : 'Crear Factura') }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { supabase } from '@/supabase.js'

const props = defineProps({
  show: Boolean,
  billing: Object,
  isEdit: Boolean
})

const emit = defineEmits(['close', 'saved'])

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
  payment_day: null,
  cut_day: null,
  payment_reminder: null,
  overdue_invoices: 0,
  status: 'pending'
})

// Watch for billing prop changes (edit mode)
watch(() => props.billing, (newBilling) => {
  if (newBilling && props.isEdit) {
    form.value = {
      router_id: newBilling.billing_router_id || null,
      id_type: newBilling.id_type || null,
      amount: newBilling.amount || null,
      create_invoice: newBilling.create_invoice || new Date().toISOString().split('T')[0],
      payment_day: newBilling.payment_day || null,
      cut_day: newBilling.cut_day || null,
      payment_reminder: newBilling.payment_reminder || null,
      overdue_invoices: newBilling.overdue_invoices || 0,
      status: newBilling.status || 'pending'
    }
  }
}, { immediate: true })

// Methods
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
      payment_day: form.value.payment_day,
      cut_day: form.value.cut_day || null,
      payment_reminder: form.value.payment_reminder || null,
      overdue_invoices: form.value.overdue_invoices || 0,
      status: form.value.status
    }

    let result
    if (props.isEdit && props.billing?.id) {
      // Update
      const { data, error } = await supabase
        .from('billing')
        .update(payload)
        .eq('id', props.billing.id)
        .select()
        .single()

      if (error) throw error
      result = data
    } else {
      // Create
      const { data, error } = await supabase
        .from('billing')
        .insert(payload)
        .select()
        .single()

      if (error) throw error
      result = data
    }

    alert(props.isEdit ? 'Factura actualizada correctamente' : 'Factura creada correctamente')
    emit('saved', result)
    closeModal()
  } catch (error) {
    console.error('Error saving billing:', error)
    alert('Error al guardar la factura: ' + error.message)
  } finally {
    loading.value = false
  }
}

const closeModal = () => {
  if (!loading.value) {
    // Reset form
    form.value = {
      router_id: null,
      id_type: null,
      amount: null,
      create_invoice: new Date().toISOString().split('T')[0],
      payment_day: null,
      cut_day: null,
      payment_reminder: null,
      overdue_invoices: 0,
      status: 'pending'
    }
    emit('close')
  }
}

onMounted(() => {
  loadRouters()
  loadBillingTypes()
})
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active .bg-white,
.modal-leave-active .bg-white {
  transition: transform 0.3s ease;
}

.modal-enter-from .bg-white,
.modal-leave-to .bg-white {
  transform: scale(0.9);
}
</style>
