<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import billingService from '@/services/billing'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import { usePermissions } from '@/composables/usePermissions'

const route = useRoute()
const router = useRouter()
const { can } = usePermissions()
const invoice = ref(null)
const loading = ref(true)

const fetchInvoice = async () => {
    loading.value = true
    try {
        const response = await billingService.getInvoice(route.params.id)
        invoice.value = response.data
    } catch (e) {
        console.error('Error loading invoice', e)
    } finally {
        loading.value = false
    }
}

const newItem = ref({ description: '', amount: 0, type: 'adjustment' })
const addItem = async () => {
    try {
        await billingService.addItems(invoice.value.id, newItem.value)
        newItem.value = { description: '', amount: 0, type: 'adjustment' }
        fetchInvoice()
    } catch (e) {
        alert('Error adding item')
    }
}

const showDeleteModal = ref(false)
const deleting = ref(false)
const confirmDelete = async () => {
    if (!invoice.value) return
    deleting.value = true
    try {
        await billingService.deleteInvoice(invoice.value.id)
        router.push('/billing/invoices')
    } catch (e) {
        console.error('Error deleting invoice', e)
        alert(e.response?.data?.message || 'No se pudo eliminar la factura.')
        deleting.value = false
    }
}

const marking = ref(false)
const showUnpaidModal = ref(false)
const confirmMarkUnpaid = async () => {
    if (!invoice.value) return
    marking.value = true
    try {
        await billingService.markUnpaid(invoice.value.id)
        showUnpaidModal.value = false
        await fetchInvoice()
    } catch (e) {
        console.error('Error marking invoice unpaid', e)
        alert('No se pudo marcar la factura como no pagada.')
    } finally {
        marking.value = false
    }
}

const downloadPdf = async () => {
    if (!invoice.value) return
    try {
        const response = await billingService.downloadPdf(invoice.value.id)
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `Invoice-${invoice.value.number}.pdf`)
        document.body.appendChild(link)
        link.click()
    } catch (e) {
        alert('Error downloading PDF')
    }
}

const getStatusColor = (status) => {
    switch (status) {
        case 'paid':      return 'text-emerald-700 bg-emerald-100'
        case 'pending':   return 'text-amber-700 bg-amber-100'
        case 'overdue':   return 'text-rose-700 bg-rose-100'
        case 'issued':    return 'text-indigo-700 bg-white'
        case 'cancelled': return 'text-slate-700 bg-slate-200'
        default:          return 'text-slate-700 bg-slate-200'
    }
}

const statusLabels = {
    paid:      'Pagado',
    pending:   'Pendiente de pago',
    overdue:   'Vencida',
    issued:    'Emitida',
    cancelled: 'Cancelada',
}
const getStatusLabel = (status) => statusLabels[status] ?? status

const formatDate = (date) => {
    if (!date) return '—'
    const d = new Date(date)
    if (isNaN(d.getTime())) return date
    return d.toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: '2-digit' })
}

onMounted(fetchInvoice)
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">
        <div class="max-w-5xl mx-auto">
            <!-- Top Controls -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                <button @click="$router.push('/billing/invoices')" 
                    class="flex items-center gap-2 text-slate-500 dark:text-slate-400 font-medium hover:text-indigo-600 transition-colors">
                    <v-icon name="md-arrowback" class="w-5 h-5" />
                    Volver a Listado
                </button>
                <div class="flex gap-3">
                    <button v-if="invoice && (invoice.status === 'paid' || Number(invoice.balance_due) <= 0)"
                        @click="showUnpaidModal = true"
                        class="p-3 bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 rounded-xl border border-rose-200 dark:border-rose-800 hover:bg-rose-100 dark:hover:bg-rose-900/40 transition-all flex items-center gap-2">
                        <v-icon name="ri-arrow-go-back-line" class="w-5 h-5" />
                        Marcar como no pagada
                    </button>
                    <button v-if="can('delete_invoice')" @click="showDeleteModal = true"
                        class="p-3 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 dark:hover:bg-red-900/40 transition-all flex items-center gap-2">
                        <v-icon name="md-delete" class="w-5 h-5" />
                        Eliminar
                    </button>
                    <button @click="downloadPdf"
                        class="p-3 bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-300 rounded-xl border border-slate-200 dark:border-gray-700 hover:bg-slate-50 transition-all flex items-center gap-2">
                        <v-icon name="md-filedownload" class="w-5 h-5" />
                        PDF
                    </button>
                    <button class="p-3 bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-300 rounded-xl border border-slate-200 dark:border-gray-700 hover:bg-slate-50 transition-all">
                        <v-icon name="md-print" class="w-5 h-5" />
                    </button>
                    <button class="p-3 bg-white dark:bg-gray-800 text-slate-600 dark:text-slate-300 rounded-xl border border-slate-200 dark:border-gray-700 hover:bg-slate-50 transition-all">
                        <v-icon name="md-share" class="w-5 h-5" />
                    </button>
                </div>
            </div>

            <!-- Main Paper -->
            <div v-if="!loading && invoice" class="bg-white dark:bg-gray-800 shadow-2xl shadow-slate-200/50 dark:shadow-none rounded-[2.5rem] border border-slate-100 dark:border-gray-700 overflow-hidden">
                <!-- Header Ribbon -->
                <div class="bg-indigo-600 p-10 text-white relative overflow-hidden">
                     <div class="absolute right-0 top-0 opacity-10"><v-icon name="la-money-bill-wave-solid" class="w-64 h-64 -mr-10 -mt-10" /></div>
                     <div class="relative flex justify-between items-start">
                         <div>
                             <h2 class="text-xs font-medium uppercase tracking-[0.3em] mb-4 opacity-70">Documento Electrónico</h2>
                             <h1 class="text-4xl font-medium mb-2">Factura No. {{ invoice.number }}</h1>
                             <div class="flex items-center gap-6 mt-6">
                                 <div class="flex flex-col">
                                     <span class="text-[10px] font-medium uppercase opacity-60">Emisión</span>
                                     <span class="font-medium">{{ formatDate(invoice.issue_date) }}</span>
                                 </div>
                                 <div class="flex flex-col">
                                     <span class="text-[10px] font-medium uppercase opacity-60">Vencimiento</span>
                                     <span class="font-medium text-indigo-200">{{ formatDate(invoice.due_date) }}</span>
                                 </div>
                             </div>
                         </div>
                         <div class="flex flex-col items-end">
                             <div :class="getStatusColor(invoice.status)" class="px-6 py-2 rounded-2xl font-medium uppercase text-sm tracking-widest shadow-lg">
                                 {{ getStatusLabel(invoice.status) }}
                             </div>
                             <div class="mt-8 text-right">
                                 <p class="text-xs font-medium opacity-70">Total del Periodo</p>
                                 <p class="text-4xl font-medium">${{ Number(invoice.total).toLocaleString() }}</p>
                             </div>
                         </div>
                     </div>
                </div>

                <!-- Body -->
                <div class="p-10">
                    <!-- Billing Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-10 mb-12">
                        <div class="space-y-4">
                            <h3 class="flex items-center gap-2 text-xs font-medium uppercase tracking-widest text-indigo-600 dark:text-indigo-400">
                                <v-icon name="bi-person" class="w-4 h-4" /> Facturado a:
                            </h3>
                            <div class="p-6 bg-slate-50 dark:bg-gray-900 rounded-[2rem]">
                                <p class="text-xl font-medium text-slate-900 dark:text-white">
                                    {{ invoice.customer?.customer_profile ? (invoice.customer.customer_profile.name + ' ' + invoice.customer.customer_profile.last_name) : (invoice.customer?.user_name || 'Desconocido') }}
                                </p>
                                <p class="text-slate-500 dark:text-slate-400 mt-1">{{ invoice.customer?.email }}</p>
                                <p class="text-slate-500 dark:text-slate-400 text-sm mt-2">ID: {{ invoice.customer?.identification || 'N/A' }}</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <h3 class="flex items-center gap-2 text-xs font-medium uppercase tracking-widest text-indigo-600 dark:text-indigo-400">
                                <v-icon name="la-dollar-sign-solid" class="w-4 h-4" /> Estado de Cuenta:
                            </h3>
                            <div class="p-6 border-2 border-dashed border-slate-200 dark:border-gray-700 rounded-[2rem]">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-slate-500 font-medium">Saldo Pendiente:</span>
                                    <span class="text-2xl font-medium text-rose-600 dark:text-rose-400">${{ Number(invoice.balance_due).toLocaleString() }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-slate-400">Periodo:</span>
                                    <span class="text-slate-600 dark:text-slate-300 font-medium">{{ formatDate(invoice.period_start) }} — {{ formatDate(invoice.period_end) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <div class="mb-12">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-xs font-medium uppercase tracking-widest text-slate-400 border-b border-slate-100 dark:border-gray-700">
                                    <th class="pb-4">Descripción del Item</th>
                                    <th class="pb-4 text-right">Cant</th>
                                    <th class="pb-4 text-right">Unitario</th>
                                    <th class="pb-4 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50 dark:divide-gray-700">
                                <tr v-for="item in invoice.items" :key="item.id" class="group">
                                    <td class="py-6">
                                        <p class="font-medium text-slate-900 dark:text-white group-hover:text-indigo-600 transition-colors">{{ item.description }}</p>
                                        <span class="text-[10px] font-medium uppercase text-slate-400 bg-slate-100 dark:bg-gray-900 px-2 py-0.5 rounded">{{ item.type }}</span>
                                    </td>
                                    <td class="py-6 text-right text-slate-600 dark:text-slate-400">{{ Number(item.quantity) }}</td>
                                    <td class="py-6 text-right text-slate-600 dark:text-slate-400">${{ Number(item.unit_price).toLocaleString() }}</td>
                                    <td class="py-6 text-right font-medium text-slate-900 dark:text-white">${{ Number(item.amount).toLocaleString() }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Item (Only for drafts/pending) -->
                    <div v-if="invoice.status !== 'paid'" class="mb-12 p-6 bg-slate-50 dark:bg-gray-900 rounded-3xl flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-[300px]">
                            <label class="block text-[10px] font-medium uppercase text-slate-400 mb-2 px-2">Ajuste manual / Adicional</label>
                            <input v-model="newItem.description" placeholder="Escriba la descripción..." class="w-full bg-white dark:bg-gray-800 border-none rounded-2xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white">
                        </div>
                        <div class="w-32">
                            <label class="block text-[10px] font-medium uppercase text-slate-400 mb-2 px-2">Monto</label>
                            <input v-model="newItem.amount" type="number" class="w-full bg-white dark:bg-gray-800 border-none rounded-2xl py-3 px-4 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:text-white font-medium [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none appearance-none">
                        </div>
                        <button @click="addItem" class="bg-indigo-600 hover:bg-indigo-700 text-white p-3 rounded-2xl transition-all shadow-lg shadow-indigo-200 dark:shadow-none">
                            <v-icon name="md-add" class="w-6 h-6" />
                        </button>
                    </div>

                    <!-- Totals Box -->
                    <div class="flex justify-end">
                        <div class="w-full max-w-sm space-y-4">
                            <div class="flex justify-between text-slate-500">
                                <span class="font-medium">Subtotal:</span>
                                <span>${{ Number(invoice.subtotal).toLocaleString() }}</span>
                            </div>
                            <div class="flex justify-between border-t-4 border-double border-slate-100 dark:border-gray-700 pt-4 text-3xl font-medium text-slate-900 dark:text-white">
                                <span>TOTAL:</span>
                                <span>${{ Number(invoice.total).toLocaleString() }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="p-10 bg-slate-50 dark:bg-gray-900 border-t border-slate-100 dark:border-gray-700 text-center">
                    <p class="text-xs text-slate-400 font-medium">Gracias por preferir nuestros servicios. Para dudas contacte a soporte.</p>
                </div>
            </div>
            
            <div v-else-if="loading" class="animate-pulse space-y-8">
                 <div class="h-32 bg-white dark:bg-gray-800 rounded-3xl"></div>
                 <div class="h-96 bg-white dark:bg-gray-800 rounded-3xl"></div>
            </div>
        </div>

        <!-- Confirmación: Marcar como no pagada -->
        <ConfirmModal
            :visible="showUnpaidModal"
            variant="warning"
            title="Marcar como no pagada"
            message="Se revertirá el pago asignado y el saldo volverá al total. La factura quedará como vencida/pendiente. ¿Deseas continuar?"
            confirm-text="Sí, marcar como no pagada"
            cancel-text="Cancelar"
            :loading="marking"
            @confirm="confirmMarkUnpaid"
            @cancel="showUnpaidModal = false"
        />

        <!-- Confirmación: Eliminar factura -->
        <ConfirmModal
            :visible="showDeleteModal"
            variant="danger"
            title="Eliminar factura"
            :message="invoice ? `Vas a eliminar la factura #${invoice.number} de forma permanente. Si tiene pagos, el monto se devolverá como saldo a favor del cliente. Esta acción no se puede deshacer.` : ''"
            require-text="ELIMINAR"
            confirm-text="Eliminar"
            loading-text="Eliminando..."
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="showDeleteModal = false"
        />
    </div>
</template>
