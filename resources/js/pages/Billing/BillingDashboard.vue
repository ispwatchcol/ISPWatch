<script setup>
import { ref, onMounted } from 'vue'
import billingService from '@/services/billing'

const stats = ref(null)
const loading = ref(true)
const user = ref({})

const fetchStats = async () => {
    loading.value = true
    try {
        const response = await billingService.getStats(user.value?.tenant_id)
        stats.value = response.data
    } catch (e) {
        console.error('Error fetching stats', e)
    } finally {
        loading.value = false
    }
}

const runGeneration = async () => {
    const period = prompt('Seleccione el periodo (YYYY-MM):', new Date().toISOString().slice(0, 7))
    if (!period) return
    loading.value = true
    try {
        const res = await billingService.runMonthly(period)
        alert(res.data.message)
        fetchStats()
    } catch (e) {
        alert('Error: ' + (e.response?.data?.message || e.message))
    } finally {
        loading.value = false
    }
}

const getStatusColor = (status) => {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
        case 'pending': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
        default: return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-400'
    }
}

onMounted(() => {
    const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
    if (stored) user.value = JSON.parse(stored)
    fetchStats()
})
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-10 gap-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white tracking-tight">Panel de Finanzas</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-2 font-medium">Resumen general de facturación y recaudos.</p>
            </div>
            <div class="flex gap-4">
                <button @click="fetchStats" class="p-3 bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-300 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 hover:bg-slate-50 dark:hover:bg-gray-700 transition-all">
                    <v-icon name="bi-arrow-repeat" class="w-6 h-6" :class="{ 'animate-spin': loading }" />
                </button>
                <button @click="runGeneration" class="px-6 py-3 bg-slate-800 dark:bg-gray-700 hover:bg-slate-900 text-white font-medium rounded-2xl transition-all shadow-md flex items-center gap-2">
                    <v-icon name="bi-calendar" class="w-6 h-6" />
                    Generar Mes
                </button>
                <button @click="$router.push('/billing/payments/new')" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none flex items-center gap-2">
                    <v-icon name="md-add" class="w-6 h-6" />
                    Nuevo Pago
                </button>
            </div>
        </div>

        <!-- Stats Grid -->
        <div v-if="!loading && stats" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
            <!-- Invoiced -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-24 h-24 bg-indigo-50 dark:bg-indigo-900/20 rounded-full transition-transform group-hover:scale-110"></div>
                <div class="relative">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="p-3 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-2xl">
                            <v-icon name="la-money-bill-wave-solid" class="w-6 h-6" />
                        </div>
                        <span class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Facturado</span>
                    </div>
                    <div class="text-3xl font-medium text-slate-900 dark:text-white">
                        <span class="text-lg text-slate-400 mr-1">{{ stats.currency }}</span>{{ stats.summary.total_invoiced.toLocaleString() }}
                    </div>
                </div>
            </div>

            <!-- Collected -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-50 dark:bg-emerald-900/20 rounded-full transition-transform group-hover:scale-110"></div>
                <div class="relative">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="p-3 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 rounded-2xl">
                            <v-icon name="md-payments-outlined" class="w-6 h-6" />
                        </div>
                        <span class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Recaudado</span>
                    </div>
                    <div class="text-3xl font-medium text-slate-900 dark:text-white">
                        <span class="text-lg text-slate-400 mr-1">{{ stats.currency }}</span>{{ stats.summary.total_paid.toLocaleString() }}
                    </div>
                </div>
            </div>

            <!-- Pending -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-24 h-24 bg-rose-50 dark:bg-rose-900/20 rounded-full transition-transform group-hover:scale-110"></div>
                <div class="relative">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="p-3 bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 rounded-2xl">
                            <v-icon name="md-trendingdown" class="w-6 h-6" />
                        </div>
                        <span class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pendiente</span>
                    </div>
                    <div class="text-3xl font-medium text-slate-900 dark:text-white">
                        <span class="text-lg text-slate-400 mr-1">{{ stats.currency }}</span>{{ stats.summary.total_pending.toLocaleString() }}
                    </div>
                </div>
            </div>

            <!-- Rate -->
            <div class="bg-white dark:bg-gray-800 p-6 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 relative overflow-hidden group">
                <div class="absolute -right-4 -top-4 w-24 h-24 bg-amber-50 dark:bg-amber-900/20 rounded-full transition-transform group-hover:scale-110"></div>
                <div class="relative">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="p-3 bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 rounded-2xl">
                            <v-icon name="hi-trending-up" class="w-6 h-6" />
                        </div>
                        <span class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Tasa de Cobro</span>
                    </div>
                    <div class="text-3xl font-medium text-slate-900 dark:text-white">
                        {{ stats.summary.collection_rate }}%
                    </div>
                    <div class="w-full bg-slate-100 dark:bg-gray-700 h-2 rounded-full mt-4">
                        <div class="bg-amber-500 h-2 rounded-full transition-all duration-1000" :style="{ width: stats.summary.collection_rate + '%' }"></div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="loading" class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-10">
            <div v-for="i in 4" :key="i" class="h-32 bg-white dark:bg-gray-800 animate-pulse rounded-3xl border border-slate-100 dark:border-gray-700"></div>
        </div>

        <!-- Main Content Area -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
            <!-- Recent Invoices -->
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-slate-50 dark:border-gray-700 flex justify-between items-center bg-slate-50/50 dark:bg-gray-900/30">
                    <h3 class="font-medium text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-wide text-sm">
                        <v-icon name="la-money-bill-wave-solid" class="w-5 h-5 text-indigo-500" />
                        Facturas Recientes
                    </h3>
                    <router-link to="/billing/invoices" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">Ver todas</router-link>
                </div>
                <div class="p-0">
                    <div v-if="!loading && stats?.recent_invoices.length > 0" class="divide-y divide-slate-50 dark:divide-gray-700">
                        <div v-for="invoice in stats.recent_invoices" :key="invoice.id" class="p-4 hover:bg-slate-50 dark:hover:bg-gray-700/50 transition-colors flex items-center justify-between group">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-gray-900 flex items-center justify-center font-medium text-slate-500 dark:text-slate-400 text-xs text-center leading-3">
                                    {{ new Date(invoice.issue_date).getUTCDate() }}<br><span class="text-[10px]">{{ new Date(invoice.issue_date).getUTCMonth() + 1 }}</span>
                                </div>
                                <div>
                                    <div class="font-medium text-slate-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors">#{{ invoice.number }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ invoice.customer?.customer_profile ? (invoice.customer.customer_profile.name + ' ' + invoice.customer.customer_profile.last_name) : (invoice.customer?.user_name || 'Desconocido') }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-slate-900 dark:text-white">${{ Number(invoice.total).toLocaleString() }}</div>
                                <span :class="getStatusColor(invoice.status)" class="px-2 py-0.5 rounded-lg text-[10px] font-medium uppercase">{{ invoice.status }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-else class="p-10 text-center">
                         <p class="text-slate-400">No hay facturas recientes.</p>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-slate-50 dark:border-gray-700 flex justify-between items-center bg-slate-50/50 dark:bg-gray-900/30">
                    <h3 class="font-medium text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-wide text-sm">
                        <v-icon name="md-payments-outlined" class="w-5 h-5 text-emerald-500" />
                        Últimos Recaudos
                    </h3>
                    <router-link to="/billing/payments" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">Ver todos</router-link>
                </div>
                <div class="p-0">
                    <div v-if="!loading && stats?.recent_payments.length > 0" class="divide-y divide-slate-50 dark:divide-gray-700">
                        <div v-for="payment in stats.recent_payments" :key="payment.id" class="p-4 hover:bg-slate-50 dark:hover:bg-gray-700/50 transition-colors flex items-center justify-between group">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                                    <v-icon name="md-add" class="w-5 h-5" />
                                </div>
                                <div>
                                    <div class="font-medium text-slate-900 dark:text-white">
                                        {{ payment.customer?.customer_profile ? (payment.customer.customer_profile.name + ' ' + payment.customer.customer_profile.last_name) : (payment.customer?.user_name || 'Desconocido') }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ payment.method }} • {{ payment.payment_date }}</div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-emerald-600 dark:text-emerald-400">+${{ Number(payment.amount).toLocaleString() }}</div>
                                <div class="text-[10px] text-slate-400 font-medium uppercase">{{ payment.reference || 'Sin Ref' }}</div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="p-10 text-center">
                         <p class="text-slate-400">No hay recaudos recientes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
