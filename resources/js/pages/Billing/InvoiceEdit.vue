<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import billingService from '@/services/billing'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const errorMsg = ref('')
const invoice = ref(null)

const form = ref({
    status: '',
    total: 0,
    balance_due: 0,
    issue_date: '',
    due_date: '',
    period_start: '',
    period_end: '',
    notes: '',
})

const statusOptions = [
    { value: 'issued',    label: 'Emitida' },
    { value: 'pending',   label: 'Pendiente de pago' },
    { value: 'paid',      label: 'Pagado' },
    { value: 'overdue',   label: 'Vencida' },
    { value: 'cancelled', label: 'Cancelada' },
]

const fetchInvoice = async () => {
    loading.value = true
    try {
        const res = await billingService.getInvoice(route.params.id)
        invoice.value = res.data
        const d = res.data
        form.value = {
            status:       d.status       ?? 'issued',
            total:        Number(d.total       ?? 0),
            balance_due:  Number(d.balance_due ?? 0),
            issue_date:   d.issue_date   ? d.issue_date.split('T')[0]   : '',
            due_date:     d.due_date     ? d.due_date.split('T')[0]     : '',
            period_start: d.period_start ? d.period_start.split('T')[0] : '',
            period_end:   d.period_end   ? d.period_end.split('T')[0]   : '',
            notes:        d.notes        ?? '',
        }
    } catch (e) {
        console.error(e)
        errorMsg.value = 'No se pudo cargar la factura.'
    } finally {
        loading.value = false
    }
}

onMounted(fetchInvoice)

const handleSubmit = async () => {
    saving.value = true
    errorMsg.value = ''
    try {
        await billingService.updateInvoice(route.params.id, form.value)
        router.push(`/billing/invoices/${route.params.id}`)
    } catch (e) {
        errorMsg.value = e.response?.data?.message || 'Error al guardar los cambios.'
    } finally {
        saving.value = false
    }
}
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <div class="max-w-2xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <button @click="router.push(`/billing/invoices/${route.params.id}`)"
                    class="flex items-center gap-2 text-slate-500 dark:text-slate-400 font-medium hover:text-indigo-600 transition-colors">
                    <v-icon name="md-arrowback" class="w-5 h-5" />
                    Volver a Detalle
                </button>
            </div>

            <!-- Loading -->
            <div v-if="loading" class="flex justify-center items-center py-24">
                <v-icon name="bi-arrow-repeat" class="w-8 h-8 animate-spin text-indigo-500" />
            </div>

            <!-- Form -->
            <div v-else-if="invoice" class="bg-white dark:bg-gray-800 shadow-xl shadow-slate-200/50 dark:shadow-none rounded-3xl border border-slate-200 dark:border-gray-700 overflow-hidden">
                <!-- Title bar -->
                <div class="bg-indigo-600 px-8 py-6 text-white">
                    <p class="text-xs font-medium uppercase tracking-widest opacity-70 mb-1">Editando</p>
                    <h1 class="text-2xl font-medium">Factura No. {{ invoice.number }}</h1>
                </div>

                <form @submit.prevent="handleSubmit" class="p-8 space-y-6">
                    <!-- Estado -->
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Estado</label>
                        <select v-model="form.status" required
                            class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                            <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                    </div>

                    <!-- Valor -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Valor Total</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <v-icon name="la-dollar-sign-solid" class="h-5 w-5 text-emerald-500" />
                                </div>
                                <input v-model.number="form.total" type="number" min="0" step="0.01" required
                                    class="block w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all font-medium text-lg"
                                    placeholder="0">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Saldo Pendiente</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <v-icon name="la-dollar-sign-solid" class="h-5 w-5 text-rose-500" />
                                </div>
                                <input v-model.number="form.balance_due" type="number" min="0" step="0.01" required
                                    class="block w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all font-medium text-lg"
                                    placeholder="0">
                            </div>
                        </div>
                    </div>

                    <!-- Fechas -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Fecha de Emisión</label>
                            <input v-model="form.issue_date" type="date" required
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Fecha de Vencimiento</label>
                            <input v-model="form.due_date" type="date" required
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Inicio del Período</label>
                            <input v-model="form.period_start" type="date"
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Fin del Período</label>
                            <input v-model="form.period_end" type="date"
                                class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                        </div>
                    </div>

                    <!-- Notas -->
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-2">Notas</label>
                        <textarea v-model="form.notes" rows="3"
                            class="block w-full px-4 py-3 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-2xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all"
                            placeholder="Observaciones opcionales..."></textarea>
                    </div>

                    <!-- Error -->
                    <div v-if="errorMsg" class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl px-4 py-3 text-sm text-red-700 dark:text-red-400">
                        <span class="text-lg">⚠</span>
                        {{ errorMsg }}
                    </div>

                    <!-- Botones -->
                    <div class="flex gap-4 pt-2">
                        <button type="button" @click="router.push(`/billing/invoices/${route.params.id}`)"
                            class="flex-1 py-3 px-6 border-2 border-slate-300 dark:border-gray-600 rounded-2xl text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-gray-700 transition-all font-medium">
                            Cancelar
                        </button>
                        <button type="submit" :disabled="saving"
                            class="flex-1 py-3 px-6 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none disabled:opacity-50 flex items-center justify-center gap-2">
                            <v-icon v-if="saving" name="bi-arrow-repeat" class="w-5 h-5 animate-spin" />
                            {{ saving ? 'Guardando...' : 'Guardar Cambios' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Error state -->
            <div v-else class="text-center py-24 text-slate-500 dark:text-slate-400">
                {{ errorMsg || 'Factura no encontrada.' }}
            </div>
        </div>
    </div>
</template>
