<template>
  <div>
    <!-- Resumen -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
      <div class="bg-gradient-to-br from-rose-500 to-red-600 text-white rounded-xl p-5 shadow">
        <p class="text-xs font-medium uppercase tracking-widest opacity-80">Saldo Pendiente</p>
        <p class="text-3xl font-bold mt-2">${{ fmt(balance) }}</p>
      </div>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
        <p class="text-xs font-medium uppercase tracking-widest text-gray-500 dark:text-gray-400">Facturas Abiertas</p>
        <p class="text-3xl font-bold mt-2 text-gray-800 dark:text-white">{{ openInvoices.length }}</p>
      </div>
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-5 flex flex-col justify-between">
        <p class="text-xs font-medium uppercase tracking-widest text-gray-500 dark:text-gray-400">Acción</p>
        <button
          @click="openPaymentModal(null)"
          class="mt-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2.5 rounded-lg transition">
          + Registrar Pago
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-10 text-gray-500 dark:text-gray-400">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
      <p class="mt-3 text-sm">Cargando facturas...</p>
    </div>

    <!-- Sin facturas -->
    <div v-else-if="invoices.length === 0" class="text-center py-12 bg-gray-50 dark:bg-gray-900 rounded-xl border border-dashed border-gray-300 dark:border-gray-700">
      <p class="text-gray-500 dark:text-gray-400">Este cliente no tiene facturas registradas.</p>
    </div>

    <!-- Tabla de facturas -->
    <div v-else class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-xl">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wider">
          <tr>
            <th class="px-4 py-3 text-left">Factura</th>
            <th class="px-4 py-3 text-left">Periodo</th>
            <th class="px-4 py-3 text-left">Vencimiento</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-right">Saldo</th>
            <th class="px-4 py-3 text-center">Estado</th>
            <th class="px-4 py-3 text-center">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
          <tr v-for="inv in invoices" :key="inv.id" class="bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/40">
            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">#{{ inv.number }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ fmtDate(inv.period_start) }} — {{ fmtDate(inv.period_end) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ fmtDate(inv.due_date) }}</td>
            <td class="px-4 py-3 text-right text-gray-800 dark:text-white">${{ fmt(inv.total) }}</td>
            <td class="px-4 py-3 text-right font-semibold" :class="Number(inv.balance_due) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
              ${{ fmt(inv.balance_due) }}
            </td>
            <td class="px-4 py-3 text-center">
              <span :class="statusClass(inv.status)" class="px-2.5 py-1 rounded-full text-[11px] font-semibold uppercase">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-center gap-2">
                <button
                  v-if="Number(inv.balance_due) > 0"
                  @click="openPaymentModal(inv)"
                  class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium transition">
                  Cargar pago
                </button>
                <button
                  @click="downloadPdf(inv)"
                  class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-xs font-medium transition">
                  PDF
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de pago -->
    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="showModal = false">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Registrar Pago</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
          <template v-if="targetInvoice">Factura #{{ targetInvoice.number }} — saldo ${{ fmt(targetInvoice.balance_due) }}</template>
          <template v-else>El pago se aplicará a las facturas más antiguas (FIFO).</template>
        </p>

        <form @submit.prevent="submitPayment" class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Valor</label>
            <input v-model.number="payForm.amount" type="number" step="0.01" min="0.01" required
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Forma de Pago</label>
              <select v-model="payForm.method"
                class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha</label>
              <input v-model="payForm.payment_date" type="date" required
                class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Referencia</label>
            <input v-model="payForm.reference" type="text" placeholder="No. comprobante / transacción"
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas</label>
            <textarea v-model="payForm.notes" rows="2"
              class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
          </div>

          <p v-if="modalError" class="text-sm text-rose-600 dark:text-rose-400">{{ modalError }}</p>

          <div class="flex gap-3 pt-2">
            <button type="submit" :disabled="submitting"
              class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-lg font-medium transition">
              {{ submitting ? 'Procesando...' : 'Confirmar Pago' }}
            </button>
            <button type="button" @click="showModal = false"
              class="px-5 bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-lg transition">
              Cancelar
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import billingService from '@/services/billing'
import { apiClient } from '@/services/api'

const props = defineProps({
  customerId: { type: [String, Number], required: true },
})
const emit = defineEmits(['notify'])

const invoices = ref([])
const balance = ref(0)
const loading = ref(true)
const showModal = ref(false)
const submitting = ref(false)
const modalError = ref('')
const targetInvoice = ref(null)

const tenantId = ref(null)
const paymentMethods = ref([])

const payForm = ref({
  amount: 0,
  method: '',
  payment_date: new Date().toISOString().split('T')[0],
  reference: '',
  notes: '',
})

const openInvoices = computed(() => invoices.value.filter(i => Number(i.balance_due) > 0))

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')
const fmtDate = (d) => (d ? String(d).split('T')[0] : '—')

const statusLabel = (s) => ({
  paid: 'Pagada', partial: 'Parcial', overdue: 'Vencida',
  issued: 'Emitida', void: 'Anulada', cancelled: 'Cancelada',
}[s] || s)

const statusClass = (s) => ({
  paid: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  partial: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  overdue: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
}[s] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

const fetchData = async () => {
  loading.value = true
  try {
    const [invRes, balRes] = await Promise.all([
      billingService.getInvoices({ customer_id: props.customerId }),
      billingService.getBalance(props.customerId),
    ])
    invoices.value = invRes.data.data ?? invRes.data ?? []
    balance.value = balRes.data.balance ?? 0
  } catch (e) {
    console.error('Error cargando facturación:', e)
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo cargar la facturación del cliente.' })
  } finally {
    loading.value = false
  }
}

const loadPaymentMethods = async () => {
  try {
    const { data } = await apiClient.get('/billing/payment-methods')
    paymentMethods.value = data.filter(m => m.is_active)
  } catch (e) {
    console.error('Error cargando formas de pago:', e)
  }
}

const openPaymentModal = (inv) => {
  targetInvoice.value = inv
  modalError.value = ''
  payForm.value = {
    amount: inv ? Number(inv.balance_due) : Number(balance.value) || 0,
    method: paymentMethods.value[0]?.name ?? '',
    payment_date: new Date().toISOString().split('T')[0],
    reference: '',
    notes: '',
  }
  showModal.value = true
}

const submitPayment = async () => {
  modalError.value = ''
  if (!payForm.value.amount || payForm.value.amount <= 0) {
    modalError.value = 'Ingrese un valor válido.'
    return
  }
  submitting.value = true
  try {
    const payload = {
      tenant_id: tenantId.value,
      customer_id: props.customerId,
      amount: payForm.value.amount,
      payment_date: payForm.value.payment_date,
      method: payForm.value.method,
      reference: payForm.value.reference || null,
      notes: payForm.value.notes || null,
    }
    if (targetInvoice.value) {
      payload.allocations = [{ invoice_id: targetInvoice.value.id, amount: payForm.value.amount }]
    }
    await billingService.registerPayment(payload)
    showModal.value = false
    emit('notify', { type: 'success', title: 'Pago registrado', message: 'El pago fue aplicado correctamente.' })
    await fetchData()
  } catch (e) {
    modalError.value = e.response?.data?.message || 'Error al registrar el pago.'
  } finally {
    submitting.value = false
  }
}

const downloadPdf = async (inv) => {
  try {
    const res = await billingService.downloadPdf(inv.id)
    const url = window.URL.createObjectURL(new Blob([res.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `Factura-${inv.number}.pdf`)
    document.body.appendChild(link)
    link.click()
    link.remove()
  } catch (e) {
    emit('notify', { type: 'error', title: 'Error', message: 'No se pudo descargar el PDF.' })
  }
}

onMounted(() => {
  const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
  if (stored) tenantId.value = JSON.parse(stored).tenant_id
  fetchData()
  loadPaymentMethods()
})
</script>
