<script setup>
import { ref, onMounted, watch } from 'vue'
import billingService from '@/services/billing'
import { apiClient } from '@/services/api'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

const payments       = ref({ data: [] })
const loading        = ref(true)
const user           = ref({})
const paymentMethods = ref([])

const filters = ref({ search: '', method: '' })

// ── Edit modal ────────────────────────────────────────────────────────────────
const showEditModal  = ref(false)
const editTarget     = ref(null)
const editSaving     = ref(false)
const editError      = ref('')
const editForm       = ref({ amount: 0, payment_date: '', method: '', reference: '', notes: '' })

// ── Delete modal ──────────────────────────────────────────────────────────────
const showDeleteModal  = ref(false)
const deleteTarget     = ref(null)
const deleteLoading    = ref(false)

// ── Fetch ─────────────────────────────────────────────────────────────────────
const fetchPayments = async () => {
    loading.value = true
    try {
        const res = await billingService.getPayments({ ...filters.value, tenant: user.value?.tenant_id })
        payments.value = Array.isArray(res.data)
            ? { data: res.data, total: res.data.length }
            : res.data
    } catch (e) {
        console.error('Error loading payments', e)
    } finally {
        loading.value = false
    }
}

const loadPaymentMethods = async () => {
    try {
        const { data } = await apiClient.get('/billing/payment-methods')
        paymentMethods.value = data.filter(m => m.is_active)
    } catch (e) { /* ignore */ }
}

onMounted(() => {
    const stored = localStorage.getItem('userData') || sessionStorage.getItem('userData')
    if (stored) user.value = JSON.parse(stored)
    fetchPayments()
    loadPaymentMethods()
})
watch(filters, () => fetchPayments(), { deep: true })

// ── Helpers ───────────────────────────────────────────────────────────────────
const customerName = (p) => p.customer?.customer_profile
    ? `${p.customer.customer_profile.name} ${p.customer.customer_profile.last_name}`
    : (p.customer?.user_name || 'Desconocido')

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')

// ── Edit ──────────────────────────────────────────────────────────────────────
const openEdit = (payment) => {
    editTarget.value = payment
    editError.value  = ''
    editForm.value = {
        amount:       Number(payment.amount),
        payment_date: String(payment.payment_date).split('T')[0],
        method:       payment.method,
        reference:    payment.reference || '',
        notes:        payment.notes || '',
    }
    showEditModal.value = true
}

const saveEdit = async () => {
    editError.value = ''
    if (!editForm.value.amount || editForm.value.amount <= 0) {
        editError.value = 'El monto debe ser mayor a cero.'
        return
    }
    editSaving.value = true
    try {
        await billingService.updatePayment(editTarget.value.id, editForm.value)
        showEditModal.value = false
        await fetchPayments()
    } catch (e) {
        editError.value = e.response?.data?.message || 'Error al actualizar el pago.'
    } finally {
        editSaving.value = false
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
const openDelete = (payment) => {
    deleteTarget.value = payment
    showDeleteModal.value = true
}

const confirmDelete = async () => {
    deleteLoading.value = true
    try {
        await billingService.deletePayment(deleteTarget.value.id)
        showDeleteModal.value = false
        await fetchPayments()
    } catch (e) {
        console.error(e)
    } finally {
        deleteLoading.value = false
    }
}
</script>

<template>
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300">

        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-medium text-slate-900 dark:text-white tracking-tight">Recaudos</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-1">Historial de pagos recibidos de los clientes.</p>
            </div>
            <button @click="$router.push('/billing/payments/new')"
                class="inline-flex items-center px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-2xl transition-all shadow-xl shadow-indigo-200 dark:shadow-none">
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
                        class="w-full pl-10 pr-4 py-2 bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                </div>
                <select v-model="filters.method"
                    class="bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white py-2 px-4 transition-all">
                    <option value="">Todos los Métodos</option>
                    <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
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
                            <th class="px-6 py-4 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-700">

                        <tr v-if="loading">
                            <td colspan="7" class="px-6 py-20 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <v-icon name="bi-arrow-repeat" class="w-10 h-10 text-indigo-500 animate-spin mb-4" />
                                    <p class="text-slate-500 dark:text-slate-400 font-medium animate-pulse">Cargando recaudos...</p>
                                </div>
                            </td>
                        </tr>

                        <tr v-else-if="payments.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center">
                                <v-icon name="md-payments-outlined" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" />
                                <p class="text-slate-500 dark:text-slate-400 font-medium">No hay registros de recaudos.</p>
                            </td>
                        </tr>

                        <tr v-else v-for="payment in payments.data" :key="payment.id"
                            class="hover:bg-slate-50/80 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 font-mono text-sm">
                                {{ String(payment.payment_date).split('T')[0] }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-slate-900 dark:text-white">{{ customerName(payment) }}</div>
                            </td>
                            <td class="px-6 py-4 text-right font-medium text-emerald-600 dark:text-emerald-400">
                                ${{ fmt(payment.amount) }}
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
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2">
                                    <button @click="openEdit(payment)"
                                        class="p-1.5 rounded-lg text-slate-500 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition"
                                        title="Editar">
                                        <v-icon name="md-edit" class="w-4 h-4" />
                                    </button>
                                    <button @click="openDelete(payment)"
                                        class="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition"
                                        title="Eliminar">
                                        <v-icon name="md-delete" class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Modal Editar ───────────────────────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="showEditModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4"
                @click.self="showEditModal = false">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">

                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white">Editar Recaudo</h2>
                        <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition">
                            <v-icon name="md-close" class="w-5 h-5" />
                        </button>
                    </div>

                    <form @submit.prevent="saveEdit" class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Monto</label>
                            <input v-model.number="editForm.amount" type="number" step="0.01" min="0.01" required
                                class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                            <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                Cambiar el monto re-aplica el pago a las facturas automáticamente.
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha</label>
                                <input v-model="editForm.payment_date" type="date" required
                                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Método</label>
                                <select v-model="editForm.method"
                                    class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Referencia</label>
                            <input v-model="editForm.reference" type="text"
                                class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                placeholder="No. comprobante / transacción" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Notas</label>
                            <textarea v-model="editForm.notes" rows="2"
                                class="w-full bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                        </div>

                        <p v-if="editError" class="text-sm text-rose-600 dark:text-rose-400">{{ editError }}</p>

                        <div class="flex gap-3 pt-1">
                            <button type="submit" :disabled="editSaving"
                                class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-medium transition">
                                {{ editSaving ? 'Guardando...' : 'Guardar Cambios' }}
                            </button>
                            <button type="button" @click="showEditModal = false"
                                class="px-5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-white py-2.5 rounded-xl transition">
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Modal Eliminar ─────────────────────────────────────────────────────── -->
    <ConfirmModal
        :visible="showDeleteModal"
        variant="danger"
        title="Eliminar Recaudo"
        :message="deleteTarget
            ? `¿Seguro que deseas eliminar el pago de $${fmt(deleteTarget.amount)} del cliente ${customerName(deleteTarget)}? Esta acción revertirá las asignaciones a facturas y no se puede deshacer.`
            : ''"
        confirm-text="Sí, eliminar"
        cancel-text="Cancelar"
        :loading="deleteLoading"
        @confirm="confirmDelete"
        @cancel="showDeleteModal = false"
    />
</template>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: opacity 0.2s ease; }
.modal-enter-from, .modal-leave-to       { opacity: 0; }
</style>
