<template>
  <div class="space-y-5">

    <!-- ── Resumen financiero ─────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

      <!-- Saldo pendiente neto -->
      <div :class="netBalance > 0
          ? 'bg-gradient-to-br from-rose-500 to-red-600 text-white'
          : 'bg-gradient-to-br from-emerald-500 to-teal-600 text-white'"
        class="rounded-2xl p-5 shadow-lg">
        <p class="text-xs font-semibold uppercase tracking-widest opacity-80">Saldo Pendiente</p>
        <p class="text-3xl font-bold mt-2">${{ fmt(netBalance) }}</p>
        <p class="text-xs mt-1 opacity-70">
          {{ netBalance > 0 ? 'Monto que el cliente aún debe' : 'El cliente está al día' }}
        </p>
      </div>

      <!-- Saldo a favor -->
      <div :class="creditBalance > 0
          ? 'bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg'
          : 'bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700'"
        class="rounded-2xl p-5 relative">
        <div class="flex items-start justify-between">
          <p class="text-xs font-semibold uppercase tracking-widest"
            :class="creditBalance > 0 ? 'opacity-80' : 'text-gray-500 dark:text-gray-400'">
            Saldo a Favor
          </p>
          <button @click="openCreditModal"
            title="Ajustar saldo a favor"
            :class="creditBalance > 0
              ? 'text-white/70 hover:text-white'
              : 'text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400'"
            class="p-1 rounded-lg transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
              <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
            </svg>
          </button>
        </div>
        <p class="text-3xl font-bold mt-2"
          :class="creditBalance > 0 ? '' : 'text-gray-800 dark:text-white'">
          ${{ fmt(creditBalance) }}
        </p>
        <p class="text-xs mt-1"
          :class="creditBalance > 0 ? 'opacity-70' : 'text-gray-400 dark:text-gray-500'">
          {{ creditBalance > 0 ? 'Se aplicará a la próxima factura' : 'Sin crédito acumulado' }}
        </p>
      </div>

      <!-- Facturas abiertas -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Facturas Abiertas</p>
        <p class="text-3xl font-bold mt-2 text-gray-800 dark:text-white">{{ openInvoices.length }}</p>
        <p class="text-xs mt-1 text-gray-400 dark:text-gray-500">
          {{ openInvoices.length === 1 ? 'factura con saldo pendiente' : 'facturas con saldo pendiente' }}
        </p>
      </div>

      <!-- Acción -->
      <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 flex flex-col justify-between">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500 dark:text-gray-400">Acción</p>
        <button @click="openPaymentModal(null)"
          class="mt-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2.5 rounded-xl transition">
          + Registrar Pago
        </button>
      </div>
    </div>

    <!-- ── Banner: saldo a favor ──────────────────────────────────────── -->
    <Transition name="slide-down">
      <div v-if="creditBalance > 0"
        class="flex items-start gap-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-2xl px-5 py-4">
        <div class="shrink-0 w-10 h-10 bg-indigo-100 dark:bg-indigo-800 rounded-xl flex items-center justify-center text-indigo-600 dark:text-indigo-300 text-xl font-bold">
          ✦
        </div>
        <div>
          <p class="font-semibold text-indigo-800 dark:text-indigo-200">Este cliente tiene ${{ fmt(creditBalance) }} a su favor</p>
          <p class="text-sm text-indigo-600 dark:text-indigo-400 mt-0.5">
            Este crédito proviene de un pago anterior mayor al saldo que debía.
            Se descontará automáticamente de la próxima factura que se genere.
            <template v-if="netBalance > 0">
              El saldo real a cobrar hoy es <strong>${{ fmt(netBalance) }}</strong>
              ({{ fmt(grossBalance) }} de facturas − {{ fmt(creditBalance) }} de crédito).
            </template>
            <template v-else>
              En este momento el cliente no debe nada; el crédito cubre todas sus facturas abiertas.
            </template>
          </p>
        </div>
      </div>
    </Transition>

    <!-- ── Banner: facturas vencidas ─────────────────────────────────── -->
    <Transition name="slide-down">
      <div v-if="overdueInvoices.length > 0"
        class="flex items-start gap-4 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-700 rounded-2xl px-5 py-4">
        <div class="shrink-0 w-10 h-10 bg-rose-100 dark:bg-rose-800 rounded-xl flex items-center justify-center text-rose-600 dark:text-rose-300 text-xl font-bold">
          !
        </div>
        <div>
          <p class="font-semibold text-rose-800 dark:text-rose-200">
            {{ overdueInvoices.length }} factura{{ overdueInvoices.length > 1 ? 's' : '' }} vencida{{ overdueInvoices.length > 1 ? 's' : '' }}
          </p>
          <p class="text-sm text-rose-600 dark:text-rose-400 mt-0.5">
            Saldo vencido acumulado: <strong>${{ fmt(overdueInvoices.reduce((s, i) => s + Number(i.balance_due), 0)) }}</strong>.
            Se recomienda gestionar el cobro o suspender el servicio.
          </p>
        </div>
      </div>
    </Transition>

    <!-- ── Loading ────────────────────────────────────────────────────── -->
    <div v-if="loading" class="text-center py-10 text-gray-500 dark:text-gray-400">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
      <p class="mt-3 text-sm">Cargando facturas...</p>
    </div>

    <!-- ── Sin facturas ───────────────────────────────────────────────── -->
    <div v-else-if="invoices.length === 0"
      class="text-center py-12 bg-gray-50 dark:bg-gray-900 rounded-2xl border border-dashed border-gray-300 dark:border-gray-700">
      <p class="text-gray-500 dark:text-gray-400">Este cliente no tiene facturas registradas.</p>
    </div>

    <!-- ── Tabla de facturas ──────────────────────────────────────────── -->
    <div v-else class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-2xl">
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
          <tr v-for="inv in invoices" :key="inv.id"
            class="bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/40 transition">
            <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">#{{ inv.number }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ fmtDate(inv.period_start) }} — {{ fmtDate(inv.period_end) }}</td>
            <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
              <span :class="isOverdue(inv) ? 'text-rose-600 dark:text-rose-400 font-medium' : ''">
                {{ fmtDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-3 text-right text-gray-800 dark:text-white">${{ fmt(inv.total) }}</td>
            <td class="px-4 py-3 text-right font-semibold"
              :class="Number(inv.balance_due) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
              ${{ fmt(inv.balance_due) }}
            </td>
            <td class="px-4 py-3 text-center">
              <span :class="statusClass(inv.status)" class="px-2.5 py-1 rounded-full text-[11px] font-semibold uppercase">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-center gap-2">
                <button v-if="Number(inv.balance_due) > 0"
                  @click="openPaymentModal(inv)"
                  class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-medium transition">
                  Cargar pago
                </button>
                <button @click="downloadPdf(inv)"
                  class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-lg text-xs font-medium transition">
                  PDF
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal ajuste saldo a favor ───────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showCreditModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
          @click.self="showCreditModal = false">
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-sm p-6">

            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Ajustar Saldo a Favor</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
              Saldo actual: <strong>${{ fmt(creditBalance) }}</strong>
            </p>

            <form @submit.prevent="submitCreditUpdate" class="space-y-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Nuevo Saldo a Favor</label>
                <input v-model.number="creditForm.amount" type="number" step="0.01" min="0" required
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Razón del ajuste</label>
                <input v-model="creditForm.reason" type="text" placeholder="Ej: corrección de pago duplicado"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>

              <p v-if="creditError" class="text-sm text-rose-600 dark:text-rose-400">{{ creditError }}</p>

              <div class="flex gap-3 pt-1">
                <button type="submit" :disabled="creditSubmitting"
                  class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition">
                  {{ creditSubmitting ? 'Guardando...' : 'Guardar' }}
                </button>
                <button type="button" @click="showCreditModal = false"
                  class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Modal de pago ──────────────────────────────────────────────── -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
          @click.self="showModal = false">
          <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">

            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Registrar Pago</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
              <template v-if="targetInvoice">
                Factura #{{ targetInvoice.number }} — saldo ${{ fmt(targetInvoice.balance_due) }}
              </template>
              <template v-else>
                El pago se aplicará a las facturas más antiguas (FIFO).
              </template>
            </p>

            <form @submit.prevent="submitPayment" class="space-y-4">
              <!-- Monto -->
              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Valor</label>
                <input v-model.number="payForm.amount" type="number" step="0.01" min="0.01" required
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />

                <!-- Badge tipo de pago -->
                <Transition name="fade">
                  <div v-if="modalPaymentType" class="mt-2 flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-xl border w-fit"
                    :class="modalPaymentTypeMeta.classes">
                    <span>{{ modalPaymentTypeMeta.icon }}</span>
                    <span>{{ modalPaymentTypeMeta.label }}</span>
                  </div>
                </Transition>
              </div>

              <!-- Info exceso -->
              <Transition name="slide-down">
                <div v-if="modalPaymentType === 'excess'"
                  class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-700 rounded-xl px-4 py-3 text-sm text-indigo-700 dark:text-indigo-300">
                  El excedente de <strong>${{ fmt(payForm.amount - (targetInvoice ? Number(targetInvoice.balance_due) : netBalance)) }}</strong>
                  se guardará como saldo a favor y se descontará automáticamente de la próxima factura.
                </div>
              </Transition>

              <!-- Info pago parcial -->
              <Transition name="slide-down">
                <div v-if="modalPaymentType === 'partial'"
                  class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                  Quedará un saldo pendiente de
                  <strong>${{ fmt((targetInvoice ? Number(targetInvoice.balance_due) : netBalance) - payForm.amount) }}</strong>
                  por cobrar.
                </div>
              </Transition>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Forma de Pago</label>
                  <select v-model="payForm.method"
                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha</label>
                  <input v-model="payForm.payment_date" type="date" required
                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                </div>
              </div>

              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Referencia</label>
                <input v-model="payForm.reference" type="text" placeholder="No. comprobante / transacción"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
              </div>

              <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas</label>
                <textarea v-model="payForm.notes" rows="2"
                  class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
              </div>

              <p v-if="modalError" class="text-sm text-rose-600 dark:text-rose-400">{{ modalError }}</p>

              <div class="flex gap-3 pt-1">
                <button type="submit" :disabled="submitting"
                  class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition">
                  {{ submitting ? 'Procesando...' : 'Confirmar Pago' }}
                </button>
                <button type="button" @click="showModal = false"
                  class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                  Cancelar
                </button>
              </div>
            </form>
          </div>
        </div>
      </Transition>
    </Teleport>
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

const invoices       = ref([])
const grossBalance   = ref(0)   // sum of invoice balance_due
const creditBalance  = ref(0)   // credit_balance from overpayments
const netBalance     = ref(0)   // what they actually owe
const loading        = ref(true)
const showModal      = ref(false)
const submitting     = ref(false)
const modalError     = ref('')
const targetInvoice  = ref(null)
const tenantId         = ref(null)
const paymentMethods   = ref([])
const showCreditModal  = ref(false)
const creditSubmitting = ref(false)
const creditError      = ref('')
const creditForm       = ref({ amount: 0, reason: '' })

const payForm = ref({
  amount: 0,
  method: '',
  payment_date: new Date().toISOString().split('T')[0],
  reference: '',
  notes: '',
})

const openInvoices    = computed(() => invoices.value.filter(i => Number(i.balance_due) > 0))
const overdueInvoices = computed(() => invoices.value.filter(i => i.status === 'overdue'))

const isOverdue = (inv) => inv.status === 'overdue'
const fmt       = (n) => Number(n || 0).toLocaleString('es-CO')
const fmtDate   = (d) => (d ? String(d).split('T')[0] : '—')

const statusLabel = (s) => ({
  paid: 'Pagada', partial: 'Parcial', overdue: 'Vencida',
  issued: 'Emitida', void: 'Anulada', cancelled: 'Cancelada',
}[s] || s)

const statusClass = (s) => ({
  paid:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  partial:  'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  overdue:  'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
  issued:   'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
}[s] || 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')

// Payment type for the modal badge
const modalPaymentType = computed(() => {
  const amt = Number(payForm.value.amount)
  const ref = targetInvoice.value ? Number(targetInvoice.value.balance_due) : netBalance.value
  if (!amt || !ref) return null
  if (amt === ref) return 'exact'
  if (amt < ref)   return 'partial'
  return 'excess'
})

const modalPaymentTypeMeta = computed(() => ({
  exact:   { label: 'Pago exacto',    classes: 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-700', icon: '✓' },
  partial: { label: 'Pago parcial',   classes: 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-700',           icon: '⬇' },
  excess:  { label: 'Pago en exceso', classes: 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-400 border-indigo-200 dark:border-indigo-700',     icon: '⬆' },
}[modalPaymentType.value] ?? { label: '', classes: '', icon: '' }))

const fetchData = async () => {
  loading.value = true
  try {
    const [invRes, balRes] = await Promise.all([
      billingService.getInvoices({ customer_id: props.customerId }),
      billingService.getBalance(props.customerId),
    ])
    invoices.value      = invRes.data.data ?? invRes.data ?? []
    grossBalance.value  = balRes.data.balance        ?? 0
    creditBalance.value = balRes.data.credit_balance ?? 0
    netBalance.value    = balRes.data.net_balance    ?? balRes.data.balance ?? 0
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
  modalError.value    = ''
  payForm.value = {
    amount: inv ? Number(inv.balance_due) : Number(netBalance.value) || 0,
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
      tenant_id:    tenantId.value,
      customer_id:  props.customerId,
      amount:       payForm.value.amount,
      payment_date: payForm.value.payment_date,
      method:       payForm.value.method,
      reference:    payForm.value.reference || null,
      notes:        payForm.value.notes || null,
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
    const url  = window.URL.createObjectURL(new Blob([res.data]))
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

const openCreditModal = () => {
  creditForm.value = { amount: creditBalance.value, reason: '' }
  creditError.value = ''
  showCreditModal.value = true
}

const submitCreditUpdate = async () => {
  creditError.value = ''
  creditSubmitting.value = true
  try {
    await billingService.updateCredit(props.customerId, creditForm.value.amount, creditForm.value.reason)
    showCreditModal.value = false
    emit('notify', { type: 'success', title: 'Saldo actualizado', message: 'El saldo a favor fue ajustado correctamente.' })
    await fetchData()
  } catch (e) {
    creditError.value = e.response?.data?.message || 'Error al actualizar el saldo.'
  } finally {
    creditSubmitting.value = false
  }
}

onMounted(() => {
  const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
  if (stored) tenantId.value = JSON.parse(stored).tenant_id
  fetchData()
  loadPaymentMethods()
})
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.25s ease; }
.slide-down-enter-from, .slide-down-leave-to       { opacity: 0; transform: translateY(-8px); }

.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; transform: translateY(-4px); }

.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to       { opacity: 0; }
</style>
