<script setup>
import { ref, onMounted, watch } from 'vue'
import billingService from '@/services/billing'
import api from '@/services/api'
import { useRouter } from 'vue-router'

const router = useRouter()

const invoices = ref({ data: [] })
const loading = ref(true)
const user = ref({})
const filters = ref({
    status: '',
    period: new Date().toISOString().slice(0, 7), // Default to current month
    search: ''
})

const showCreateModal = ref(false)
const customers = ref([])
const newInvoice = ref({
    customer_id: '',
    issue_date: new Date().toISOString().split('T')[0],
    due_date: new Date(Date.now() + 5*24*60*60*1000).toISOString().split('T')[0],
    period_start: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    period_end: new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toISOString().split('T')[0],
    notes: ''
})

const fetchCustomers = async () => {
    try {
        const res = await api.customers.getAll()
        customers.value = res.data.data || res.data
    } catch (e) {
        console.error(e)
    }
}

const fetchInvoices = async () => {
    loading.value = true
    try {
        let periodValue = filters.value.period
        // If it's not empty and doesn't look like YYYY-MM, try to parse it
        if (periodValue && !/^\d{4}-\d{2}$/.test(periodValue)) {
            const d = new Date(periodValue)
            if (!isNaN(d.getTime())) {
                periodValue = d.toISOString().slice(0, 7)
            }
        }

        const params = {
            ...filters.value,
            period: periodValue,
            tenant: user.value?.tenant_id
        }
        const response = await billingService.getInvoices(params)
        // Normalize: ensure it follows the Laravel pagination structure { data: [] }
        if (Array.isArray(response.data)) {
            invoices.value = { data: response.data, total: response.data.length }
        } else {
            invoices.value = response.data
        }
    } catch (e) {
        console.error('Error loading invoices', e)
    } finally {
        loading.value = false
    }
}

const getStatusColor = (status) => {
    switch (status) {
        case 'paid': return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
        case 'pending': return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
        case 'overdue': return 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400'
        default: return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-400'
    }
}

const downloadPdf = async (id, number) => {
    try {
        const response = await billingService.downloadPdf(id)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `Invoice-${number}.pdf`)
        document.body.appendChild(link)
        link.click()
    } catch (e) {
        alert('Error downloading PDF')
    }
}

const createIndividual = async () => {
    try {
        const res = await billingService.createInvoice({
            ...newInvoice.value,
            tenant_id: user.value?.tenant_id
        })
        showCreateModal.value = false
        router.push(`/billing/invoices/${res.data.id}`)
    } catch (e) {
        alert('Error: ' + (e.response?.data?.message || e.message))
    }
}

onMounted(() => {
    const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
    if (stored) user.value = JSON.parse(stored)
    fetchInvoices()
    fetchCustomers()
})

watch(filters, () => fetchInvoices(), { deep: true })
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <!-- Header Section -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-medium text-slate-900 dark:text-white tracking-tight">Facturación</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-1">Gestione sus facturas y cobros de manera eficiente.</p>
            </div>
            
            <div class="flex gap-3">
                <button @click="showCreateModal = true" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 text-slate-700 dark:text-slate-200 font-medium rounded-xl border border-slate-200 dark:border-gray-700 hover:bg-slate-50 transition-all shadow-sm">
                    <v-icon name="md-add" class="w-5 h-5 mr-2" />
                    Nueva Factura
                </button>
                <button @click="$router.push('/billing/payments/new')" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl transition-all duration-200 shadow-lg shadow-indigo-200 dark:shadow-none transform hover:-translate-y-0.5">
                    <v-icon name="md-payments-outlined" class="w-5 h-5 mr-2" />
                    Registrar Pago
                </button>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <div class="relative flex-1 min-w-[200px]">
                    <v-icon name="md-search" class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" type="text" placeholder="Buscar por cliente o número..." 
                        class="w-full pl-10 pr-4 py-2 bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white transition-all">
                </div>
                
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <v-icon name="bi-filter" class="w-5 h-5 text-slate-400" />
                        <select v-model="filters.status" class="bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white py-2 px-4 transition-all">
                            <option value="">Todos los Estados</option>
                            <option value="paid">Pagadas</option>
                            <option value="pending">Pendientes</option>
                            <option value="overdue">Vencidas</option>
                        </select>
                    </div>

                    <input v-model="filters.period" type="month" 
                        class="bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white py-2 px-4 transition-all">
                    
                    <button @click="fetchInvoices" class="p-2 bg-slate-100 dark:bg-gray-700 hover:bg-slate-200 dark:hover:bg-gray-600 rounded-xl transition-colors">
                        <v-icon name="bi-filter" class="w-5 h-5 text-slate-600 dark:text-slate-300" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Table Section -->
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 dark:bg-gray-900/50 border-b border-slate-200 dark:border-gray-700">
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Número</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Cliente</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider text-right">Total</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider text-right">Saldo</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">Estado</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Vencimiento</th>
                            <th class="px-6 py-4 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                        <tr v-if="loading">
                            <td colspan="7" class="px-6 py-20 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <v-icon name="bi-arrow-repeat" class="w-10 h-10 text-indigo-500 animate-spin mb-4" />
                                    <p class="text-slate-500 dark:text-slate-400 font-medium animate-pulse">Cargando facturas...</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-else-if="invoices.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center">
                                <v-icon name="la-money-bill-wave-solid" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" />
                                <p class="text-slate-500 dark:text-slate-400 font-medium">No se encontraron facturas.</p>
                            </td>
                        </tr>
                        <tr v-else v-for="invoice in invoices.data" :key="invoice.id" 
                            class="group hover:bg-slate-50/80 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-6 py-4 font-mono font-bold text-indigo-600 dark:text-indigo-400">#{{ invoice.number }}</td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900 dark:text-white">
                                    {{ invoice.customer?.customer_profile ? (invoice.customer.customer_profile.name + ' ' + invoice.customer.customer_profile.last_name) : (invoice.customer?.user_name || 'Desconocido') }}
                                </div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ invoice.customer?.email }}</div>
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-slate-900 dark:text-white">
                                ${{ Number(invoice.total).toLocaleString() }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span :class="Number(invoice.balance_due) > 0 ? 'text-rose-600 font-bold' : 'text-slate-400'" class="dark:text-slate-300">
                                    ${{ Number(invoice.balance_due).toLocaleString() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span :class="getStatusColor(invoice.status)" class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
                                    {{ invoice.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 text-sm font-mono">
                                {{ new Date(invoice.due_date).toISOString().split('T')[0] }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex justify-center gap-2">
                                    <button @click="$router.push(`/billing/invoices/${invoice.id}`)" 
                                        class="p-2 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded-lg transition-colors" title="Ver Detalle">
                                        <v-icon name="fa-eye" class="w-5 h-5" />
                                    </button>
                                    <button @click="downloadPdf(invoice.id, invoice.number)" 
                                        class="p-2 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-gray-600 rounded-lg transition-colors" title="Descargar PDF">
                                        <v-icon name="md-download" class="w-5 h-5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div v-if="invoices.total > invoices.per_page" class="px-6 py-4 bg-slate-50/50 dark:bg-gray-900/30 border-t border-slate-200 dark:border-gray-700 flex justify-between items-center text-sm">
                <div class="text-slate-500 dark:text-slate-400">
                    Mostrando <span class="font-bold text-slate-900 dark:text-white">{{ invoices.from }}</span> a <span class="font-bold text-slate-900 dark:text-white">{{ invoices.to }}</span> de {{ invoices.total }}
                </div>
                <div class="flex gap-2">
                    <button class="px-4 py-2 bg-white dark:bg-gray-800 border border-slate-200 dark:border-gray-700 rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50">Anterior</button>
                    <button class="px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-colors">Siguiente</button>
                </div>
            </div>
        </div>

        <!-- Create Invoice Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-800 w-full max-w-lg rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-300">
                <div class="p-8">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-medium text-slate-900 dark:text-white">Nueva Factura</h3>
                        <button @click="showCreateModal = false" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors">
                            <v-icon name="io-close" class="w-6 h-6" />
                        </button>
                    </div>

                    <form @submit.prevent="createIndividual" class="space-y-6">
                        <div>
                            <label class="block text-xs font-medium text-slate-400 uppercase tracking-widest mb-2 px-2">Seleccionar Cliente</label>
                            <select v-model="newInvoice.customer_id" required
                                class="w-full bg-slate-50 dark:bg-gray-900 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                                <option value="">Seleccione un cliente</option>
                                <option v-for="c in customers" :key="c.user_id || c.id" :value="c.user_id || c.id">
                                    {{ c.name }} {{ c.last_name }}
                                </option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-400 uppercase tracking-widest mb-2 px-2">F. Emisión</label>
                                <input type="date" v-model="newInvoice.issue_date" required
                                    class="w-full bg-slate-50 dark:bg-gray-900 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-400 uppercase tracking-widest mb-2 px-2">F. Vencimiento</label>
                                <input type="date" v-model="newInvoice.due_date" required
                                    class="w-full bg-slate-50 dark:bg-gray-900 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-slate-400 uppercase tracking-widest mb-2 px-2">Inicio Periodo</label>
                                <input type="date" v-model="newInvoice.period_start" required
                                    class="w-full bg-slate-50 dark:bg-gray-900 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-400 uppercase tracking-widest mb-2 px-2">Fin Periodo</label>
                                <input type="date" v-model="newInvoice.period_end" required
                                    class="w-full bg-slate-50 dark:bg-gray-900 border-none rounded-2xl py-3 px-4 focus:ring-2 focus:ring-indigo-500 dark:text-white">
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" 
                                class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none translate-y-0 active:translate-y-1">
                                Crear Factura y Abrir Detalle
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
