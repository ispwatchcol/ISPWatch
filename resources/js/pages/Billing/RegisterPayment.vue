<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import billingService from '@/services/billing'
import api, { apiClient } from '@/services/api'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'

const user = ref({})
const customers = ref([])
const paymentMethods = ref([])
const loading = ref(false)
const successInfo = ref(null)
const errorMsg = ref('')

const form = ref({
    customer_id: '',
    amount: 0,
    payment_date: new Date().toISOString().split('T')[0],
    method: '',
    reference: '',
    notes: '',
    tenant_id: null
})

const customerBalance  = ref(0)
const creditBalance    = ref(0)
const netBalance       = ref(0)
const showPaymentModal = ref(false)
const showCreditModal  = ref(false)
const creditSubmitting = ref(false)
const creditError      = ref('')
const creditForm       = ref({ amount: 0, reason: '' })

// 'exact' | 'partial' | 'excess'  — compared against the net balance owed
const paymentType = computed(() => {
    const amt = Number(form.value.amount)
    const bal = netBalance.value
    if (!amt || bal === undefined) return null
    if (amt === bal) return 'exact'
    if (amt < bal)   return 'partial'
    return 'excess'
})

const paymentTypeMeta = computed(() => ({
    exact:   { label: 'Pago exacto',    classes: 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 border-emerald-200 dark:border-emerald-700', icon: '✓' },
    partial: { label: 'Pago parcial',   classes: 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-700',   icon: '⬇' },
    excess:  { label: 'Pago en exceso', classes: 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-700',         icon: '⬆' },
}[paymentType.value] ?? null))

const modalMessage = computed(() => {
    const amt     = Number(form.value.amount).toLocaleString('es-CO')
    const bal     = Number(netBalance.value).toLocaleString('es-CO')
    const surplus = Number(form.value.amount - netBalance.value).toLocaleString('es-CO')
    if (paymentType.value === 'excess') {
        return `El monto ingresado ($${amt}) supera el saldo pendiente ($${bal}). El excedente de $${surplus} quedará registrado como saldo a favor del cliente y se aplicará automáticamente a la próxima factura.`
    }
    return ''
})

const customerLabel = (c) => `${c.name} ${c.last_name}`

watch(() => form.value.customer_id, (val) => { if (val) getBalance() })

const fetchCustomers = async () => {
    try {
        const res = await api.customers.getAll()
        customers.value = res.data.data || res.data
    } catch (e) {
        console.error(e)
    }
}

const fetchPaymentMethods = async () => {
    try {
        const { data } = await apiClient.get('/billing/payment-methods')
        paymentMethods.value = data.filter(m => m.is_active)
        if (paymentMethods.value.length > 0 && !form.value.method) {
            form.value.method = paymentMethods.value[0].name
        }
    } catch (e) {
        console.error(e)
    }
}

const getBalance = async () => {
    if (!form.value.customer_id) return
    customerBalance.value = 0
    creditBalance.value   = 0
    netBalance.value      = 0
    try {
        const res = await billingService.getBalance(form.value.customer_id)
        customerBalance.value = res.data.balance        ?? 0
        creditBalance.value   = res.data.credit_balance ?? 0
        netBalance.value      = res.data.net_balance    ?? res.data.balance ?? 0
    } catch (e) {
        // ignore
    }
}

const submitPayment = () => {
    errorMsg.value = ''
    successInfo.value = null
    if (paymentType.value === 'excess') {
        showPaymentModal.value = true
    } else {
        doRegister()
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
        await billingService.updateCredit(form.value.customer_id, creditForm.value.amount, creditForm.value.reason)
        showCreditModal.value = false
        successInfo.value = { allocations: [] }
        getBalance()
    } catch (e) {
        creditError.value = e.response?.data?.message || 'Error al actualizar el saldo.'
    } finally {
        creditSubmitting.value = false
    }
}

const doRegister = async () => {
    showPaymentModal.value = false
    loading.value = true
    try {
        const res = await billingService.registerPayment(form.value)
        successInfo.value = res.data
        form.value.amount = 0
        form.value.reference = ''
        form.value.notes = ''
        getBalance()
    } catch (e) {
        errorMsg.value = e.response?.data?.message || e.message || 'Error al registrar el pago.'
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
    if (stored) {
        user.value = JSON.parse(stored)
        form.value.tenant_id = user.value.tenant_id
    }
    fetchCustomers()
    fetchPaymentMethods()
})
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <div class="max-w-3xl mx-auto">
            <h1 class="text-4xl font-medium text-slate-900 dark:text-white mb-10 tracking-tight">Registrar Recaudo</h1>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Form Column -->
                <div class="md:col-span-2 space-y-6">
                    <div class="bg-white dark:bg-gray-800 shadow-xl shadow-slate-200/50 dark:shadow-none rounded-3xl border border-slate-200 dark:border-gray-700 p-8">
                        <form @submit.prevent="submitPayment" class="space-y-6">
                            <!-- Customer Select -->
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Cliente</label>
                                <SearchableSelect
                                    v-model="form.customer_id"
                                    :items="customers"
                                    item-key="user_id"
                                    :item-label="customerLabel"
                                    item-icon="bi-person"
                                    placeholder="Seleccione un cliente"
                                    search-placeholder="Buscar por nombre..."
                                    :required="true"
                                />
                            </div>

                            <!-- Amount -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Valor del Pago</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <v-icon name="la-dollar-sign-solid" class="h-5 w-5 text-emerald-500" />
                                        </div>
                                        <input v-model="form.amount" type="number" step="0.01"
                                            class="block w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all font-medium text-xl"
                                            placeholder="0.00" required>
                                    </div>
                                    <!-- Payment type badge -->
                                    <Transition name="fade">
                                        <div v-if="paymentTypeMeta" class="mt-2 flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-xl border w-fit" :class="paymentTypeMeta.classes">
                                            <span>{{ paymentTypeMeta.icon }}</span>
                                            <span>{{ paymentTypeMeta.label }}</span>
                                        </div>
                                    </Transition>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Forma de Pago</label>
                                    <SearchableSelect
                                        v-model="form.method"
                                        :items="paymentMethods"
                                        item-key="name"
                                        item-label="name"
                                        item-icon="bi-credit-card"
                                        placeholder="Seleccione forma de pago"
                                        search-placeholder="Buscar método..."
                                    />
                                </div>
                            </div>

                            <!-- Date & Reference -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Fecha de Pago</label>
                                    <div class="relative">
                                        <input v-model="form.payment_date" type="date" class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all" required>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Referencia / No. Comprobante</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <v-icon name="bi-list-task" class="h-5 w-5 text-slate-400" />
                                        </div>
                                        <input v-model="form.reference" type="text" class="block w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all" placeholder="ID de transacción">
                                    </div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Notas</label>
                                <textarea v-model="form.notes" rows="3" class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all"></textarea>
                            </div>

                            <!-- Error message -->
                            <div v-if="errorMsg" class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl px-4 py-3 text-sm text-red-700 dark:text-red-400">
                                <span class="text-lg">⚠</span>
                                {{ errorMsg }}
                            </div>

                            <button type="submit" :disabled="loading"
                                class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-lg rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none disabled:bg-slate-300 dark:disabled:bg-slate-800 flex items-center justify-center gap-2">
                                <v-icon v-if="loading" name="bi-arrow-repeat" class="w-6 h-6 animate-spin" />
                                {{ loading ? 'Procesando...' : 'Confirmar Recaudo' }}
                            </button>
                        </form>
                    </div>

                    <!-- Success Alert -->
                    <div v-if="successInfo" class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800 p-6 rounded-3xl">
                        <div class="flex items-center gap-4">
                            <v-icon name="bi-check-circle-fill" class="w-10 h-10 text-emerald-500" />
                            <div>
                                <h4 class="font-medium text-emerald-800 dark:text-emerald-400 text-lg">Pago Registrado</h4>
                                <p class="text-emerald-700 dark:text-emerald-500 text-sm">Se aplicó abono a {{ successInfo.allocations?.length }} facturas.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Column -->
                <div class="space-y-6">
                    <!-- Balance Card -->
                    <div class="bg-indigo-600 p-8 rounded-3xl text-white shadow-2xl shadow-indigo-300 dark:shadow-none">
                        <h4 class="text-xs font-medium uppercase tracking-widest opacity-80 mb-1">Saldo Pendiente</h4>
                        <div class="text-4xl font-medium mb-1">${{ Number(netBalance).toLocaleString('es-CO') }}</div>
                        <div v-if="creditBalance > 0" class="flex items-center gap-2 mb-2">
                            <span class="text-xs font-medium bg-white/20 rounded-lg px-2 py-1 inline-block">
                                + ${{ Number(creditBalance).toLocaleString('es-CO') }} de saldo a favor
                            </span>
                            <button v-if="form.customer_id" @click="openCreditModal"
                                title="Ajustar saldo a favor"
                                class="text-white/70 hover:text-white transition p-1 rounded">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                                </svg>
                            </button>
                        </div>
                        <button v-if="form.customer_id && creditBalance === 0" @click="openCreditModal"
                            class="text-xs text-white/70 hover:text-white transition mb-2 flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                            </svg>
                            Ajustar crédito
                        </button>
                        <p class="text-sm opacity-70">Saldo neto descontando crédito disponible.</p>
                    </div>

                    <!-- Help Card -->
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-3xl border border-slate-200 dark:border-gray-700">
                        <h4 class="font-bold text-slate-900 dark:text-white mb-2">Asignación Automática</h4>
                        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                            Los pagos se aplicarán automáticamente a las facturas más antiguas (método FIFO) hasta agotar el saldo.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Credit adjustment modal -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showCreditModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
                @click.self="showCreditModal = false">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-sm p-6">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-1">Ajustar Saldo a Favor</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">
                        Saldo actual: <strong>${{ Number(creditBalance).toLocaleString('es-CO') }}</strong>
                    </p>
                    <form @submit.prevent="submitCreditUpdate" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Nuevo Saldo a Favor</label>
                            <input v-model.number="creditForm.amount" type="number" step="0.01" min="0" required
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Razón del ajuste</label>
                            <input v-model="creditForm.reason" type="text" placeholder="Ej: corrección de pago duplicado"
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all" />
                        </div>
                        <div v-if="creditError" class="text-sm text-red-600 dark:text-red-400">{{ creditError }}</div>
                        <div class="flex gap-3 pt-1">
                            <button type="submit" :disabled="creditSubmitting"
                                class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition disabled:opacity-50">
                                {{ creditSubmitting ? 'Guardando...' : 'Guardar' }}
                            </button>
                            <button type="button" @click="showCreditModal = false"
                                class="px-5 bg-slate-100 dark:bg-gray-700 hover:bg-slate-200 text-slate-800 dark:text-white rounded-2xl transition">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Overpayment confirmation modal -->
    <ConfirmModal
        :visible="showPaymentModal"
        variant="info"
        title="Pago en exceso"
        :message="modalMessage"
        confirm-text="Registrar de todos modos"
        cancel-text="Cancelar"
        :loading="loading"
        @confirm="doRegister"
        @cancel="showPaymentModal = false"
    />
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; transform: translateY(-4px); }
</style>
