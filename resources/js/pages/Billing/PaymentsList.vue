<script setup>
import { ref, onMounted, watch } from 'vue'
import billingService from '@/services/billing'

const payments = ref({ data: [] })
const loading = ref(true)
const user = ref({})
const filters = ref({
    search: '',
    method: ''
})

const fetchPayments = async () => {
    loading.value = true
    try {
        const params = {
            ...filters.value,
            tenant: user.value?.tenant_id
        }
        const response = await billingService.getPayments(params)
        // Normalize
        if (Array.isArray(response.data)) {
            payments.value = { data: response.data, total: response.data.length }
        } else {
            payments.value = response.data
        }
    } catch (e) {
        console.error('Error loading payments', e)
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
    if (stored) user.value = JSON.parse(stored)
    fetchPayments()
})

watch(filters, () => fetchPayments(), { deep: true })
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-medium text-slate-900 dark:text-white tracking-tight text-gradient bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-indigo-400">Recaudos</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-1">Historial de pagos recibidos de los clientes.</p>
            </div>
            
            <button @click="$router.push('/billing/payments/new')" class="inline-flex items-center px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none">
                <v-icon name="md-add" class="w-6 h-6 mr-2" />
                Registrar Recaudo
            </button>
        </div>

        <!-- Filters -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <div class="relative flex-1 min-w-[200px]">
                    <v-icon name="md-search" class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" type="text" placeholder="Buscar por cliente o referencia..." 
                        class="w-full pl-10 pr-4 py-2 bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all">
                </div>
                
                <select v-model="filters.method" class="bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white py-2 px-4 transition-all">
                    <option value="">Todos los Métodos</option>
                    <option value="cash">Efectivo</option>
                    <option value="transfer">Transferencia</option>
                    <option value="consignation">Consignación</option>
                </select>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 dark:bg-gray-900/50 border-b border-slate-200 dark:border-gray-700">
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Fecha</th>
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Cliente</th>
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-right">Monto</th>
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">Método</th>
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Referencia</th>
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Facturas Afectadas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                        <!-- Loading State -->
                        <tr v-if="loading">
                            <td colspan="6" class="px-6 py-20 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <v-icon name="bi-arrow-repeat" class="w-10 h-10 text-indigo-500 animate-spin mb-4" />
                                    <p class="text-slate-500 dark:text-slate-400 font-medium animate-pulse">Cargando recaudos...</p>
                                </div>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-else-if="payments.data.length === 0">
                            <td colspan="6" class="px-6 py-12 text-center">
                                <v-icon name="md-payments-outlined" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" />
                                <p class="text-slate-500 dark:text-slate-400 font-medium">No hay registros de recaudos.</p>
                            </td>
                        </tr>

                        <!-- Data State -->
                        <tr v-else v-for="payment in payments.data" :key="payment.id" class="hover:bg-slate-50/80 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 font-mono text-sm">
                                {{ new Date(payment.payment_date).toISOString().split('T')[0] }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-slate-900 dark:text-white">
                                    {{ payment.customer?.customer_profile ? (payment.customer.customer_profile.name + ' ' + payment.customer.customer_profile.last_name) : (payment.customer?.user_name || 'Desconocido') }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right font-medium text-emerald-600 dark:text-emerald-400">
                                ${{ Number(payment.amount).toLocaleString() }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-full text-xs font-medium uppercase tracking-wider">
                                    {{ payment.method }}
                                </span>
                            </td>
                            <td class="px-6 py-4 font-mono text-sm text-slate-500 dark:text-slate-400">
                                {{ payment.reference || 'N/A' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="alloc in payment.allocations" :key="alloc.id" 
                                        class="px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded text-[10px] font-medium">
                                        #{{ alloc.invoice?.number }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
