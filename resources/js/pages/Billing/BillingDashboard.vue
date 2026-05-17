<script setup>
import { ref, onMounted } from 'vue'
import billingService from '@/services/billing'

const stats = ref(null)
const loading = ref(true)
const user = ref({})

const showPeriodModal = ref(false)
const periodInput = ref(new Date().toISOString().slice(0, 7))
const statusModal = ref({
    show: false,
    title: '',
    message: '',
    type: 'success' // success, error, info
})

// Custom Month Picker Logic
const pickerYear = ref(new Date().getFullYear())
const months = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
]

const selectMonth = (index) => {
    const month = (index + 1).toString().padStart(2, '0')
    periodInput.value = `${pickerYear.value}-${month}`
}

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

const openPeriodModal = () => {
    periodInput.value = new Date().toISOString().slice(0, 7)
    showPeriodModal.value = true
}

const runGeneration = async () => {
    if (!periodInput.value) return
    
    showPeriodModal.value = false
    loading.value = true
    try {
        const res = await billingService.runMonthly(periodInput.value)
        statusModal.value = {
            show: true,
            title: 'Proceso Exitoso',
            message: res.data.message,
            type: 'success'
        }
        fetchStats()
    } catch (e) {
        statusModal.value = {
            show: true,
            title: 'Error de Procesamiento',
            message: e.response?.data?.message || e.message,
            type: 'error'
        }
    } finally {
        loading.value = false
    }
}

const showConfirmOverdue = ref(false)

const runOverdue = async () => {
    showConfirmOverdue.value = false
    loading.value = true
    try {
        const res = await billingService.runOverdue()
        const s = res.data.stats
        statusModal.value = {
            show: true,
            title: 'Proceso de Cortes Completado',
            message: `Suspendidos: ${s.suspended}\nManual Pendiente: ${s.manual_pending}\nSin Acción: ${s.no_action}\nErrores: ${s.errors}`,
            type: 'success'
        }
        fetchStats()
    } catch (e) {
        statusModal.value = {
            show: true,
            title: 'Error en Proceso de Cortes',
            message: e.response?.data?.message || e.message,
            type: 'error'
        }
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
            <div class="flex flex-wrap gap-3">
                <button @click="fetchStats" class="p-3 bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-300 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 hover:bg-slate-50 dark:hover:bg-gray-700 transition-all active:scale-[0.95]">
                    <v-icon name="bi-arrow-repeat" class="w-6 h-6" :class="{ 'animate-spin': loading }" />
                </button>
                <button @click="openPeriodModal" class="px-6 py-3 rounded-2xl flex items-center gap-2 transition-all hover:scale-[1.02] active:scale-[0.98] font-semibold shadow-sm
                    bg-slate-50 text-slate-700 border border-slate-200
                    hover:bg-slate-100
                    dark:bg-gray-800 dark:text-slate-300 dark:border-gray-700 dark:hover:bg-gray-700">
                    <v-icon name="bi-calendar" class="w-6 h-6" />
                    Generar Mes
                </button>
                <button @click="showConfirmOverdue = true" class="px-6 py-3 rounded-2xl flex items-center gap-2 transition-all hover:scale-[1.02] active:scale-[0.98] font-semibold shadow-sm
                    bg-rose-50 text-rose-700 border border-rose-100
                    hover:bg-rose-100
                    dark:bg-rose-900/20 dark:text-rose-400 dark:border-rose-800/50 dark:hover:bg-rose-900/30">
                    <v-icon name="md-warningamber-round" class="w-6 h-6" />
                    Procesar Cortes
                </button>
                <button @click="$router.push('/billing/payments/new')" class="inline-flex items-center px-6 py-3 rounded-2xl transition-all hover:scale-[1.02] active:scale-[0.98] font-semibold shadow-sm
                    bg-indigo-50 text-indigo-700 border border-indigo-100
                    hover:bg-indigo-100
                    dark:bg-indigo-900/20 dark:text-indigo-400 dark:border-indigo-800/50 dark:hover:bg-indigo-900/30">
                    <v-icon name="md-add" class="w-6 h-6 mr-2" />
                    Registrar Recaudo
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

        <!-- Modal: Seleccionar Periodo -->
        <div v-if="showPeriodModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl max-w-sm w-full overflow-hidden animate-in zoom-in-95 duration-200">
                <div class="p-6 border-b border-slate-50 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white">Generar Mes</h3>
                    <button @click="showPeriodModal = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-white transition"><v-icon name="io-close" class="w-6 h-6"/></button>
                </div>
                <div class="p-6 space-y-5">
                    <p class="text-slate-500 dark:text-slate-400 text-sm">Seleccione el periodo de facturación para generar los documentos del mes.</p>
                    
                    <!-- Custom Month Picker -->
                    <div class="bg-slate-50 dark:bg-gray-900/50 rounded-2xl p-4 border border-slate-200 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-4 px-2">
                            <button @click="pickerYear--" type="button" class="p-2 hover:bg-slate-200 dark:hover:bg-gray-700 rounded-xl transition text-slate-500">
                                <v-icon name="hi-chevron-left" class="w-5 h-5" />
                            </button>
                            <span class="text-lg font-black text-slate-900 dark:text-white tracking-tight">{{ pickerYear }}</span>
                            <button @click="pickerYear++" type="button" class="p-2 hover:bg-slate-200 dark:hover:bg-gray-700 rounded-xl transition text-slate-500">
                                <v-icon name="hi-chevron-right" class="w-5 h-5" />
                            </button>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <button 
                                v-for="(month, index) in months" 
                                :key="index"
                                type="button"
                                @click="selectMonth(index)"
                                :class="[
                                    periodInput === `${pickerYear}-${(index + 1).toString().padStart(2, '0')}`
                                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/30 border-transparent'
                                    : 'bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-400 border-slate-100 dark:border-gray-700 hover:border-indigo-500/50 hover:bg-slate-100 dark:hover:bg-gray-700'
                                ]"
                                class="py-2.5 px-1 rounded-xl text-[10px] font-black transition-all uppercase tracking-widest border"
                            >
                                {{ month.slice(0, 3) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-center gap-2 text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-widest">
                        <v-icon name="bi-calendar-check" class="w-4 h-4" />
                        Seleccionado: {{ periodInput }}
                    </div>
                </div>
                <div class="p-6 bg-slate-50/50 dark:bg-gray-900/50 flex gap-3">
                    <button @click="showPeriodModal = false" class="flex-1 py-3 px-4 rounded-2xl text-slate-600 dark:text-slate-400 font-semibold hover:bg-slate-100 dark:hover:bg-gray-800 transition">Cancelar</button>
                    <button @click="runGeneration" :disabled="!periodInput" class="flex-1 py-3 px-4 rounded-2xl bg-indigo-600 text-white font-semibold hover:bg-indigo-700 shadow-lg shadow-indigo-500/20 transition active:scale-95 disabled:opacity-50">Procesar</button>
                </div>
            </div>
        </div>

        <!-- Modal: Confirmar Cortes -->
        <div v-if="showConfirmOverdue" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl max-w-sm w-full overflow-hidden animate-in zoom-in-95 duration-200">
                <div class="p-8 text-center">
                    <div class="w-16 h-16 bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <v-icon name="md-warningamber-round" class="w-10 h-10" />
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">¿Procesar Cortes?</h3>
                    <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">
                        Se suspenderá el servicio a los clientes con facturas vencidas. Esta acción interactúa con los routers configurados.
                    </p>
                </div>
                <div class="p-6 bg-slate-50/50 dark:bg-gray-900/50 flex gap-3">
                    <button @click="showConfirmOverdue = false" class="flex-1 py-3 px-4 rounded-2xl text-slate-600 dark:text-slate-400 font-semibold hover:bg-slate-100 dark:hover:bg-gray-800 transition">No, volver</button>
                    <button @click="runOverdue" class="flex-1 py-3 px-4 rounded-2xl bg-rose-600 text-white font-semibold hover:bg-rose-700 shadow-lg shadow-rose-500/20 transition active:scale-95">Sí, procesar</button>
                </div>
            </div>
        </div>

        <!-- Modal: Status/Result -->
        <div v-if="statusModal.show" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
            <div class="bg-white dark:bg-gray-800 rounded-[2.5rem] shadow-2xl max-w-md w-full overflow-hidden animate-in zoom-in-95 duration-200 border border-white/20 dark:border-gray-700">
                <div class="p-8 text-center">
                    <div :class="{
                        'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400': statusModal.type === 'success',
                        'bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400': statusModal.type === 'error',
                        'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400': statusModal.type === 'info'
                    }" class="w-20 h-20 rounded-[2rem] flex items-center justify-center mx-auto mb-6">
                        <v-icon v-if="statusModal.type === 'success'" name="hi-check-circle" class="w-12 h-12" />
                        <v-icon v-if="statusModal.type === 'error'" name="md-error-outline" class="w-12 h-12" />
                        <v-icon v-if="statusModal.type === 'info'" name="hi-information-circle" class="w-12 h-12" />
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">{{ statusModal.title }}</h3>
                    <div class="text-slate-500 dark:text-slate-400 text-sm whitespace-pre-line leading-relaxed">
                        {{ statusModal.message }}
                    </div>
                </div>
                <div class="p-6 bg-slate-50/50 dark:bg-gray-900/50">
                    <button @click="statusModal.show = false" class="w-full py-4 px-4 rounded-2xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold hover:opacity-90 transition active:scale-95 shadow-xl">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>