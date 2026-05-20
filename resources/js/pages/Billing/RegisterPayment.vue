<script setup>
import { ref, onMounted } from 'vue'
import billingService from '@/services/billing'
import api, { apiClient } from '@/services/api'

const user = ref({})
const customers = ref([])
const paymentMethods = ref([])
const loading = ref(false)
const successInfo = ref(null)

const form = ref({
    customer_id: '',
    amount: 0,
    payment_date: new Date().toISOString().split('T')[0],
    method: '',
    reference: '',
    notes: '',
    tenant_id: null
})

const customerBalance = ref(0)

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
    if(!form.value.customer_id) return
    try {
        const res = await billingService.getBalance(form.value.customer_id)
        customerBalance.value = res.data.balance
    } catch (e) {
        // ignore
    }
}

const submitPayment = async () => {
    // Validar exceso de pago
    if (form.value.amount > customerBalance.value) {
        const confirmOverpayment = confirm(`El monto ingresado ($${Number(form.value.amount).toLocaleString()}) supera el saldo pendiente del cliente ($${Number(customerBalance.value).toLocaleString()}). ¿Está seguro que desea registrar este pago en exceso?`)
        if (!confirmOverpayment) return
    }

    loading.value = true
    successInfo.value = null
    try {
        const res = await billingService.registerPayment(form.value)
        successInfo.value = res.data
        form.value.amount = 0
        form.value.reference = ''
        form.value.notes = ''
        getBalance()
    } catch (e) {
        alert('Error: ' + (e.response?.data?.message || e.message))
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
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                        <v-icon name="bi-person" class="h-5 w-5 text-slate-400" />
                                    </div>
                                    <select v-model="form.customer_id" @change="getBalance" 
                                        class="block w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all appearance-none" required>
                                        <option value="">Seleccione un cliente</option>
                                        <option v-for="c in customers" :key="c.user_id || c.id" :value="c.user_id || c.id">
                                            {{ c.name }} {{ c.last_name }} 
                                        </option>
                                    </select>
                                </div>
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
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Forma de Pago</label>
                                    <select v-model="form.method" class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border-none rounded-2xl focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                                        <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                                    </select>
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

                            <button type="submit" :disabled="loading" 
                                class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-lg rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none disabled:bg-slate-300 dark:disabled:bg-slate-800 flex items-center justify-center gap-2">
                                <v-icon v-if="loading" name="bi-arrow-repeat" class="w-6 h-6 animate-spin" />
                                {{ loading ? 'Procesando...' : 'Confirmar Recaudo' }}
                            </button>
                        </form>
                    </div>

                    <!-- Success Alert -->
                    <div v-if="successInfo" class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800 p-6 rounded-3xl animate-bounce">
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
                        <h4 class="text-xs font-medium uppercase tracking-widest opacity-80 mb-4">Saldo Pendiente</h4>
                        <div class="text-4xl font-medium mb-2">${{ Number(customerBalance).toLocaleString() }}</div>
                        <p class="text-sm opacity-70">Este es el total de todas las facturas abiertas del cliente.</p>
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
</template>
