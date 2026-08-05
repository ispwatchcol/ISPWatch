<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import billingService from '@/services/billing'
import { apiClient } from '@/services/api'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'
import Pagination from '@/components/ui/Pagination.vue'
import { customerDisplayName } from '@/utils/customerName'
import { invoiceTypeLabel, invoiceTypeColor, loadInvoiceTypes } from '@/utils/invoiceType'

const payments       = ref({ data: [] })
const paymentMethods = ref([])

// Totales del filtro completo, calculados por el servidor. No se derivan de
// `payments.data`: eso sumaría sólo la página visible.
const summary = ref({ total: 0, count: 0 })

// `loading` sólo para la primera carga (no hay nada que mostrar todavía).
// Los refrescos posteriores usan `refreshing`: la tabla se queda en pantalla y
// sólo se atenúa, en vez de vaciarse y volver a llenarse en cada tecla.
const loading    = ref(true)
const refreshing = ref(false)

// Filtros específicos por columna. `search` sigue siendo la búsqueda general
// (cliente o referencia); el resto acota una sola columna cada uno.
const emptyFilters = () => ({
    search:        '',
    customer:      '',
    reference:     '',
    method:        '',
    registered_by: '',
    invoice:       '',
    date_from:     '',
    date_to:       '',
    amount_min:    '',
    amount_max:    '',
})

const filters     = ref(emptyFilters())
const showFilters = ref(false)
const sort        = ref({ by: 'payment_date', dir: 'desc' })
const currentPage = ref(1)
const perPage     = ref(15)

const activeFilterCount = computed(
    () => Object.values(filters.value).filter(v => v !== '' && v !== null).length
)

// Estilo compartido por los minibuscadores de la cabecera y por su equivalente
// en el panel de móvil.
const columnInputClass = 'w-full text-xs font-normal normal-case bg-white dark:bg-gray-800 text-slate-700 dark:text-slate-200 px-2 py-1.5 rounded-md border border-slate-300 dark:border-gray-600 focus:outline-none focus:ring-1 focus:ring-indigo-500 placeholder-slate-400'
const panelInputClass  = 'w-full bg-slate-50 dark:bg-gray-900 border-none rounded-xl px-3 py-2 text-sm dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500'

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
// El listado se pagina en el servidor: sin `page` la vista se quedaba mostrando
// solo los primeros registros y el resto únicamente aparecía al buscar.
const buildParams = () => {
    const params = {
        page:     currentPage.value,
        per_page: perPage.value,
        sort_by:  sort.value.by,
        sort_dir: sort.value.dir,
    }
    for (const [key, value] of Object.entries(filters.value)) {
        if (value !== '' && value !== null && value !== undefined) params[key] = value
    }
    return params
}

// Descarta respuestas de peticiones viejas: al teclear rápido en los filtros la
// primera puede llegar después de la última y pintar resultados obsoletos.
let requestId = 0

const fetchPayments = async () => {
    const id = ++requestId
    refreshing.value = true
    try {
        const res = await billingService.getPayments(buildParams())
        if (id !== requestId) return
        payments.value = Array.isArray(res.data)
            ? { data: res.data, total: res.data.length }
            : res.data
        summary.value = res.data?.summary ?? { total: 0, count: 0 }

        // Si pedimos una página que ya no existe (p. ej. tras borrar el último
        // recaudo de la última página) retrocedemos en vez de dejar la tabla
        // vacía.
        const meta = payments.value
        if ((meta.data?.length ?? 0) === 0 && (meta.last_page || 1) < (meta.current_page || 1)) {
            currentPage.value = meta.last_page || 1
            return fetchPayments()
        }
    } catch (e) {
        if (id === requestId) console.error('Error loading payments', e)
    } finally {
        if (id === requestId) {
            refreshing.value = false
            loading.value    = false
        }
    }
}

// Los filtros de texto se escriben letra a letra: sin este respiro se dispara
// una petición por tecla.
let debounceTimer = null
const scheduleFetch = () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(fetchPayments, 400)
}

const loadPaymentMethods = async () => {
    try {
        const { data } = await apiClient.get('/billing/payment-methods')
        paymentMethods.value = data.filter(m => m.is_active)
    } catch (e) { /* ignore */ }
}

onMounted(() => {
    fetchPayments()
    loadPaymentMethods()
    // Los chips de "Facturas afectadas" heredan el tipo de la factura: sin el
    // catálogo, un tipo propio del tenant saldría con la etiqueta genérica.
    loadInvoiceTypes()
})

// Al cambiar un filtro volvemos a la primera página: la actual puede no existir
// en el nuevo conjunto de resultados.
watch(filters, () => { currentPage.value = 1; scheduleFetch() }, { deep: true })
watch(perPage, () => { currentPage.value = 1; fetchPayments() })

const clearFilters = () => {
    filters.value = emptyFilters()
}

// ── Orden ─────────────────────────────────────────────────────────────────────
const toggleSort = (column) => {
    if (sort.value.by === column) {
        sort.value.dir = sort.value.dir === 'asc' ? 'desc' : 'asc'
    } else {
        sort.value.by  = column
        // Fechas y montos se leen mejor de mayor a menor; el texto, alfabético.
        sort.value.dir = ['payment_date', 'amount'].includes(column) ? 'desc' : 'asc'
    }
    currentPage.value = 1
    fetchPayments()
}

// Flecha en la cabecera de la columna por la que se está ordenando.
const sortIndicator = (column) => {
    if (sort.value.by !== column) return '↕'
    return sort.value.dir === 'asc' ? '↑' : '↓'
}

// ── Paginación ────────────────────────────────────────────────────────────────
const goToPage = (page) => {
    const last = payments.value.last_page || 1
    if (page < 1 || page > last || page === payments.value.current_page) return
    currentPage.value = page
    fetchPayments()
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const customerName = (p) => customerDisplayName(p.customer)

// Color estable por forma de pago: el mismo método sale siempre del mismo color
// para distinguirlos de un vistazo. Como los métodos los crea cada ISP (y suelen
// llevar el número de la cuenta pegado: "NEQUI 305..."), el color se deriva del
// nombre en vez de mantener una lista fija que se quedaría corta.
const METHOD_COLORS = [
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
    'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
    'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
    'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
]

const methodColor = (method) => {
    const name = String(method || '').trim().toLowerCase()
    if (!name) return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
    let hash = 0
    for (let i = 0; i < name.length; i++) hash = (hash * 31 + name.charCodeAt(i)) % 997
    return METHOD_COLORS[hash % METHOD_COLORS.length]
}

// Staff user who registered the payment. Null for auto-generated payments
// (installation billing, etc.) → mostramos un guion.
const registeredBy = (p) => {
    const c = p.creator
    if (!c) return '—'
    const full = `${c.user_name || ''} ${c.user_lastname || ''}`.trim()
    return full || c.name || '—'
}

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
    <div class="p-6 min-h-screen bg-slate-50 dark:bg-gray-900 transition-colors duration-300 min-w-0">

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

        <!-- Búsqueda general. Los filtros por columna viven bajo cada título de
             la tabla; en móvil, donde no hay tabla, se despliegan aquí. -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px]">
                    <v-icon name="md-search" class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input v-model="filters.search" type="text" placeholder="Buscar por cliente o referencia..."
                        class="w-full pl-10 pr-4 py-2 bg-slate-50 dark:bg-gray-900 border-none rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-white transition-all">
                </div>

                <button type="button" @click="showFilters = !showFilters"
                    class="md:hidden inline-flex items-center gap-2 px-4 py-2 rounded-xl font-medium transition-all"
                    :class="showFilters || activeFilterCount
                        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300'
                        : 'bg-slate-50 text-slate-600 hover:bg-slate-100 dark:bg-gray-900 dark:text-slate-300 dark:hover:bg-gray-700'">
                    <v-icon name="md-filterlist" class="w-5 h-5" />
                    Filtros
                    <span v-if="activeFilterCount"
                        class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-indigo-600 text-white text-[11px] font-semibold">
                        {{ activeFilterCount }}
                    </span>
                </button>

                <button v-if="activeFilterCount" type="button" @click="clearFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/20 transition-all">
                    <v-icon name="md-close" class="w-4 h-4" />
                    Limpiar filtros
                </button>
            </div>

            <!-- Mismos filtros por columna, para móvil (la tabla no se muestra) -->
            <div v-show="showFilters"
                class="md:hidden grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-slate-200 dark:border-gray-700">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Fecha desde</label>
                    <input v-model="filters.date_from" type="date" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Fecha hasta</label>
                    <input v-model="filters.date_to" type="date" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Cliente</label>
                    <input v-model="filters.customer" type="text" placeholder="Nombre o cédula" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Método</label>
                    <select v-model="filters.method" :class="panelInputClass">
                        <option value="">Todos</option>
                        <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Monto desde</label>
                    <input v-model="filters.amount_min" type="number" step="0.01" min="0" placeholder="0" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Monto hasta</label>
                    <input v-model="filters.amount_max" type="number" step="0.01" min="0" placeholder="Sin límite" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Referencia</label>
                    <input v-model="filters.reference" type="text" placeholder="No. comprobante" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Registrado por</label>
                    <input v-model="filters.registered_by" type="text" placeholder="Usuario o 'sistema'" :class="panelInputClass">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Factura afectada</label>
                    <input v-model="filters.invoice" type="text" placeholder="No. de factura" :class="panelInputClass">
                </div>
            </div>
        </div>

        <!-- Total del filtro completo (no de la página visible) -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-slate-200 dark:border-gray-700 p-5 mb-6
                    flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">
                    Total recaudado
                </p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ fmt(summary.total) }}</p>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500">
                {{ summary.count }} recaudo(s) con los filtros actuales
            </p>
        </div>

        <!-- Table / Cards -->
        <div class="relative bg-white dark:bg-gray-800 rounded-3xl md:rounded-xl shadow-xl shadow-slate-200/50 dark:shadow-none border border-slate-200 dark:border-gray-700 overflow-hidden">

            <!-- Refresco: los datos anteriores siguen visibles, sólo atenuados.
                 Vaciar la tabla en cada tecla hacía que todo pareciera lento. -->
            <div v-if="refreshing && !loading"
                class="absolute top-0 left-0 right-0 h-0.5 bg-indigo-500/30 overflow-hidden z-10">
                <div class="h-full w-1/3 bg-indigo-500 loading-bar"></div>
            </div>

            <!-- Desktop Table -->
            <div class="hidden md:block overflow-x-auto transition-opacity duration-150"
                :class="refreshing && !loading ? 'opacity-60' : ''">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/50 dark:bg-gray-900/50">
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                <button type="button" @click="toggleSort('payment_date')" class="inline-flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                    Fecha <span class="text-[10px]">{{ sortIndicator('payment_date') }}</span>
                                </button>
                            </th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Cliente</th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-right">
                                <button type="button" @click="toggleSort('amount')" class="inline-flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                    Monto <span class="text-[10px]">{{ sortIndicator('amount') }}</span>
                                </button>
                            </th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">
                                <button type="button" @click="toggleSort('method')" class="inline-flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                    Método <span class="text-[10px]">{{ sortIndicator('method') }}</span>
                                </button>
                            </th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                <button type="button" @click="toggleSort('reference')" class="inline-flex items-center gap-1 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                    Referencia <span class="text-[10px]">{{ sortIndicator('reference') }}</span>
                                </button>
                            </th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Registrado por</th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">Facturas Afectadas</th>
                            <th class="px-4 pt-4 pb-2 text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider text-center">Acciones</th>
                        </tr>

                        <!-- Minibuscador por columna -->
                        <tr class="bg-slate-50/50 dark:bg-gray-900/50 border-b border-slate-200 dark:border-gray-700">
                            <th class="px-4 pb-3 pt-0 align-top">
                                <div class="flex flex-col gap-1">
                                    <label class="flex items-center gap-1.5">
                                        <span class="w-10 shrink-0 text-[10px] font-normal normal-case text-slate-400">Desde</span>
                                        <input v-model="filters.date_from" type="date" :class="columnInputClass">
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <span class="w-10 shrink-0 text-[10px] font-normal normal-case text-slate-400">Hasta</span>
                                        <input v-model="filters.date_to" type="date" :class="columnInputClass">
                                    </label>
                                </div>
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <input v-model="filters.customer" type="text" placeholder="Nombre o cédula" :class="columnInputClass">
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <div class="flex flex-col gap-1">
                                    <label class="flex items-center gap-1.5">
                                        <span class="w-10 shrink-0 text-[10px] font-normal normal-case text-slate-400">Mín.</span>
                                        <input v-model="filters.amount_min" type="number" step="0.01" min="0" placeholder="0" :class="columnInputClass">
                                    </label>
                                    <label class="flex items-center gap-1.5">
                                        <span class="w-10 shrink-0 text-[10px] font-normal normal-case text-slate-400">Máx.</span>
                                        <input v-model="filters.amount_max" type="number" step="0.01" min="0" placeholder="Sin tope" :class="columnInputClass">
                                    </label>
                                </div>
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <select v-model="filters.method" :class="columnInputClass">
                                    <option value="">Todos</option>
                                    <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.name">{{ pm.name }}</option>
                                </select>
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <input v-model="filters.reference" type="text" placeholder="Referencia" :class="columnInputClass">
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <input v-model="filters.registered_by" type="text" placeholder="Usuario o 'sistema'" :class="columnInputClass">
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top">
                                <input v-model="filters.invoice" type="text" placeholder="No. de factura" :class="columnInputClass">
                            </th>
                            <th class="px-4 pb-3 pt-0 align-top text-center">
                                <button v-if="activeFilterCount" type="button" @click="clearFilters"
                                    class="text-xs font-normal normal-case text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Limpiar
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-700">

                        <!-- Esqueleto: sólo en la primera carga, y con la forma de
                             la tabla real para que no salte al llegar los datos. -->
                        <template v-if="loading">
                            <tr v-for="n in 6" :key="`skeleton-${n}`" class="animate-pulse">
                                <td v-for="c in 8" :key="c" class="px-4 py-4">
                                    <div class="h-4 rounded bg-slate-200 dark:bg-gray-700"
                                        :class="c === 2 ? 'w-40' : 'w-16'"></div>
                                </td>
                            </tr>
                        </template>

                        <tr v-else-if="payments.data.length === 0">
                            <td colspan="8" class="px-4 py-12 text-center">
                                <v-icon name="md-payments-outlined" class="w-12 h-12 text-slate-300 dark:text-slate-600 mx-auto mb-4" />
                                <p class="text-slate-500 dark:text-slate-400 font-medium">
                                    {{ activeFilterCount ? 'Ningún recaudo coincide con los filtros aplicados.' : 'No hay registros de recaudos.' }}
                                </p>
                                <button v-if="activeFilterCount" type="button" @click="clearFilters"
                                    class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Limpiar filtros
                                </button>
                            </td>
                        </tr>

                        <tr v-else v-for="payment in payments.data" :key="payment.id"
                            class="hover:bg-slate-50/80 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-4 py-4 text-slate-600 dark:text-slate-400 font-mono text-sm">
                                {{ String(payment.payment_date).split('T')[0] }}
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-medium text-slate-900 dark:text-white">{{ customerName(payment) }}</div>
                            </td>
                            <td class="px-4 py-4 text-right font-medium text-emerald-600 dark:text-emerald-400">
                                ${{ fmt(payment.amount) }}
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="px-3 py-1 rounded-full text-xs font-medium uppercase tracking-wider whitespace-nowrap"
                                    :class="methodColor(payment.method)">
                                    {{ payment.method }}
                                </span>
                            </td>
                            <td class="px-4 py-4 font-mono text-sm text-slate-500 dark:text-slate-400">
                                {{ payment.reference || 'N/A' }}
                            </td>
                            <td class="px-4 py-4 text-sm text-slate-600 dark:text-slate-400">
                                {{ registeredBy(payment) }}
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1">
                                    <!-- El color dice de qué tipo es la factura cubierta,
                                         con el mismo código que la columna Tipo de Facturación. -->
                                    <span v-for="alloc in payment.allocations" :key="alloc.id"
                                        class="px-2 py-0.5 rounded text-[10px] font-medium whitespace-nowrap"
                                        :class="invoiceTypeColor(alloc.invoice?.invoice_type)"
                                        :title="`${invoiceTypeLabel(alloc.invoice?.invoice_type)} · $${fmt(alloc.amount)}`">
                                        #{{ alloc.invoice?.number }}
                                    </span>
                                    <span v-if="!payment.allocations?.length" class="text-xs text-slate-400 dark:text-slate-500">
                                        Saldo a favor
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
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

            <!-- Mobile Cards -->
            <div class="md:hidden divide-y divide-slate-100 dark:divide-gray-700 transition-opacity duration-150"
                :class="refreshing && !loading ? 'opacity-60' : ''">
                <template v-if="loading">
                    <div v-for="n in 4" :key="`skeleton-card-${n}`" class="p-4 animate-pulse">
                        <div class="h-4 w-40 rounded bg-slate-200 dark:bg-gray-700 mb-3"></div>
                        <div class="h-3 w-24 rounded bg-slate-200 dark:bg-gray-700 mb-2"></div>
                        <div class="h-3 w-32 rounded bg-slate-200 dark:bg-gray-700"></div>
                    </div>
                </template>

                <div v-else-if="payments.data.length === 0" class="p-8 text-center">
                    <v-icon name="md-payments-outlined" class="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                    <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">
                        {{ activeFilterCount ? 'Ningún recaudo coincide con los filtros aplicados.' : 'No hay registros de recaudos.' }}
                    </p>
                    <button v-if="activeFilterCount" type="button" @click="clearFilters"
                        class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                        Limpiar filtros
                    </button>
                </div>

                <div v-else v-for="payment in payments.data" :key="payment.id" class="p-4 bg-white dark:bg-gray-800">
                    <div class="flex justify-between items-start mb-3">
                        <div class="font-semibold text-slate-900 dark:text-white text-sm leading-tight">
                            {{ customerName(payment) }}
                        </div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-medium uppercase tracking-wider whitespace-nowrap"
                            :class="methodColor(payment.method)">
                            {{ payment.method }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs mb-3">
                        <div><span class="text-slate-500 dark:text-slate-400">Fecha:</span> <span class="text-slate-700 dark:text-slate-300 ml-1 font-mono">{{ String(payment.payment_date).split('T')[0] }}</span></div>
                        <div class="text-right"><span class="text-slate-500 dark:text-slate-400">Monto:</span> <span class="text-emerald-600 dark:text-emerald-400 ml-1 font-semibold">${{ fmt(payment.amount) }}</span></div>
                        <div class="col-span-2"><span class="text-slate-500 dark:text-slate-400">Referencia:</span> <span class="text-slate-700 dark:text-slate-300 ml-1 font-mono">{{ payment.reference || 'N/A' }}</span></div>
                        <div class="col-span-2"><span class="text-slate-500 dark:text-slate-400">Registrado por:</span> <span class="text-slate-700 dark:text-slate-300 ml-1">{{ registeredBy(payment) }}</span></div>
                        <div class="col-span-2 mt-1 flex flex-wrap gap-1 items-center">
                            <span class="text-slate-500 dark:text-slate-400 mr-1">Facturas:</span>
                            <span v-for="alloc in payment.allocations" :key="alloc.id"
                                class="px-1.5 py-0.5 rounded text-[10px] font-medium"
                                :class="invoiceTypeColor(alloc.invoice?.invoice_type)"
                                :title="invoiceTypeLabel(alloc.invoice?.invoice_type)">
                                #{{ alloc.invoice?.number }}
                            </span>
                            <span v-if="!payment.allocations?.length" class="text-slate-400 dark:text-slate-500">Saldo a favor</span>
                        </div>
                    </div>

                    <div class="flex gap-2 pt-3 border-t border-slate-100 dark:border-gray-700">
                        <button @click="openEdit(payment)"
                            class="flex-1 py-2 rounded-lg text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 dark:text-indigo-400 dark:bg-indigo-900/20 dark:hover:bg-indigo-900/30 transition flex items-center justify-center gap-1.5">
                            <v-icon name="md-edit" class="w-3.5 h-3.5" /> Editar
                        </button>
                        <button @click="openDelete(payment)"
                            class="flex-1 py-2 rounded-lg text-xs font-medium text-rose-600 bg-rose-50 hover:bg-rose-100 dark:text-rose-400 dark:bg-rose-900/20 dark:hover:bg-rose-900/30 transition flex items-center justify-center gap-1.5">
                            <v-icon name="md-delete" class="w-3.5 h-3.5" /> Eliminar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="!loading && payments.total"
                class="px-4 md:px-6 py-4 bg-slate-50/50 dark:bg-gray-900/30 border-t border-slate-200 dark:border-gray-700 flex flex-col sm:flex-row gap-3 justify-between items-center text-sm">
                <div class="flex items-center gap-3 text-slate-500 dark:text-slate-400">
                    <span>
                        Mostrando <span class="font-bold text-slate-900 dark:text-white">{{ payments.from }}</span>
                        a <span class="font-bold text-slate-900 dark:text-white">{{ payments.to }}</span>
                        de <span class="font-bold text-slate-900 dark:text-white">{{ payments.total }}</span> recaudos
                    </span>
                    <select v-model.number="perPage" title="Recaudos por página"
                        class="bg-white dark:bg-gray-800 text-slate-700 dark:text-slate-200 text-xs px-2 py-1.5 rounded-md border border-slate-300 dark:border-gray-600 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option :value="15">15 / pág.</option>
                        <option :value="25">25 / pág.</option>
                        <option :value="50">50 / pág.</option>
                        <option :value="100">100 / pág.</option>
                        <option :value="200">200 / pág.</option>
                    </select>
                </div>
                <Pagination
                    :current-page="payments.current_page || 1"
                    :total-pages="payments.last_page || 1"
                    accent="indigo"
                    @change="goToPage"
                />
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

/* Barra indeterminada del refresco: recorre el borde superior de la tabla
   mientras llega la respuesta, sin ocultar los datos que ya están.
   La animación se declara aquí (y no como clase arbitraria de Tailwind) porque
   el scoped de Vue renombra los @keyframes y sólo reescribe las referencias que
   viven en este mismo bloque. */
.loading-bar { animation: loading-bar 1s ease-in-out infinite; }

@keyframes loading-bar {
    0%   { transform: translateX(-100%); }
    100% { transform: translateX(400%); }
}
</style>
