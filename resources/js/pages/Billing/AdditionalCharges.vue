<template>
    <div class="min-h-screen bg-slate-50 dark:bg-gray-900 p-6 transition-colors duration-300">
        <NotificationToast ref="toast" />

        <div :class="tab === 'catalogo' ? 'max-w-7xl mx-auto' : 'max-w-4xl mx-auto'">
            <!-- Header -->
            <PageHeader
                title="Servicios adicionales"
                :subtitle="tab === 'catalogo'
                    ? 'Servicios recurrentes que se asignan a varios clientes y se cobran en su factura mensual.'
                    : 'Cobra algo puntual a un cliente fuera de la factura del mes.'"
                icon="bi-plus-circle"
            >
                <template #actions>
                    <button v-if="tab === 'puntual'" @click="verCargosGenerados"
                        title="Abre Facturación mostrando sólo las facturas de este tipo, de todos los meses"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl font-medium shadow-sm transition-all
                               bg-white text-slate-700 border border-slate-200 hover:bg-slate-50
                               dark:bg-gray-800 dark:text-slate-200 dark:border-gray-700 dark:hover:bg-gray-700">
                        <v-icon name="md-list" class="w-4 h-4" />
                        Ver cargos generados
                    </button>
                    <button @click="router.push('/billing/invoices')"
                        title="Vuelve al listado completo de facturación"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl font-medium shadow-sm transition-all
                               bg-white text-slate-700 border border-slate-200 hover:bg-slate-50
                               dark:bg-gray-800 dark:text-slate-200 dark:border-gray-700 dark:hover:bg-gray-700">
                        <v-icon name="ri-arrow-go-back-line" class="w-4 h-4" />
                        Volver a facturación
                    </button>
                </template>
            </PageHeader>

            <!-- Pestañas: lo recurrente y lo puntual son cosas distintas y se
                 gestionan distinto, pero ambas son "servicios adicionales" para
                 el operador; separarlas en dos entradas del menú obligaría a
                 recordar cuál es cuál. -->
            <div class="flex gap-1 border-b border-slate-200 dark:border-gray-700 mb-6 overflow-x-auto">
                <button
                    v-for="t in tabs" :key="t.key"
                    @click="tab = t.key"
                    type="button"
                    :class="[
                        'px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition',
                        tab === t.key
                            ? 'border-emerald-600 text-emerald-600 dark:text-emerald-400'
                            : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200'
                    ]"
                >
                    {{ t.label }}
                </button>
            </div>

            <AdditionalServiceCatalog v-if="tab === 'catalogo'" @notify="onNotify" />

            <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Formulario -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Cliente y tipo -->
                    <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 p-6 space-y-5">
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Cliente</h2>
                            <SearchableSelect
                                v-model="form.customer_id"
                                :items="customers"
                                item-key="user_id"
                                :item-label="customerLabel"
                                item-icon="bi-person"
                                label="Seleccionar cliente"
                                placeholder="-- Selecciona un cliente --"
                                search-placeholder="Buscar por nombre..."
                            />
                        </div>

                        <!-- Tipo de la factura que se va a emitir: equipos, TV,
                             reconexión... del catálogo administrable. -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo de factura</label>
                            <select v-model="form.invoice_type"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option v-for="t in typeOptions" :key="t.slug" :value="t.slug">{{ t.name }}</option>
                            </select>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                ¿Falta un tipo?
                                <button type="button" @click="router.push('/billing/invoice-types')" class="text-emerald-600 dark:text-emerald-400 hover:underline">Administrar tipos de factura</button>
                            </p>
                        </div>
                    </div>

                    <!-- Ítems -->
                    <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">Ítems del Cargo</h2>
                            <button @click="addItem" class="text-sm bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded-lg flex items-center gap-1">
                                <v-icon name="md-add" class="w-4 h-4" />
                                Agregar ítem
                            </button>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(item, index) in form.items" :key="index" class="p-4 bg-gray-50 dark:bg-gray-700/40 rounded-lg border border-gray-200 dark:border-gray-600">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Descripción</label>
                                        <input
                                            v-model="item.description"
                                            type="text"
                                            placeholder="Ej: Mantenimiento de equipo"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cantidad</label>
                                        <input
                                            v-model.number="item.quantity"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Precio unitario</label>
                                        <input
                                            v-model.number="item.unit_price"
                                            type="number"
                                            min="0"
                                            step="1"
                                            onwheel="this.blur()"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">= {{ formatCurrency(item.unit_price) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Tipo (opcional)</label>
                                        <input
                                            v-model="item.type"
                                            type="text"
                                            placeholder="Ej: servicio, equipo"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                            Subtotal: <span class="text-emerald-600 dark:text-emerald-400">{{ formatCurrency(item.quantity * item.unit_price) }}</span>
                                        </span>
                                        <button
                                            v-if="form.items.length > 1"
                                            @click="removeItem(index)"
                                            class="text-red-500 hover:text-red-700 transition"
                                        >
                                            <v-icon name="md-delete" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Detalles adicionales -->
                    <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Detalles</h2>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fecha de vencimiento</label>
                                <DatePicker v-model="form.due_date" accent="emerald" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notas</label>
                                <textarea
                                    v-model="form.notes"
                                    rows="3"
                                    placeholder="Descripción general del cargo..."
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <button
                        @click="submitCharge"
                        :disabled="submitting || !isFormValid"
                        class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-2xl transition-all shadow-lg shadow-emerald-500/25 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span v-if="!submitting">Generar Cargo Adicional</span>
                        <span v-else>Generando...</span>
                    </button>
                </div>

                <!-- Panel lateral: resumen -->
                <div class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-sm border border-slate-100 dark:border-gray-700 p-6 sticky top-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Resumen</h3>

                        <div v-if="form.customer_id" class="mb-4 p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cliente seleccionado</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ selectedCustomerName }}</p>
                        </div>

                        <div class="space-y-2 mb-4">
                            <div v-for="(item, i) in form.items" :key="i" class="flex justify-between text-sm">
                                <span class="text-gray-600 dark:text-gray-400 truncate max-w-[60%]">{{ item.description || `Ítem ${i + 1}` }}</span>
                                <span class="text-gray-800 dark:text-white font-medium">{{ formatCurrency(item.quantity * item.unit_price) }}</span>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3 flex justify-between font-bold">
                            <span class="text-gray-800 dark:text-white">Total</span>
                            <span class="text-emerald-600 dark:text-emerald-400 text-lg">{{ formatCurrency(total) }}</span>
                        </div>

                        <div v-if="form.due_date" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            Vence: {{ form.due_date }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import billingService from '@/services/billing'
import api from '@/services/api'
import DatePicker from '@/components/DatePicker.vue'
import NotificationToast from '@/components/NotificationToast.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SearchableSelect from '@/components/SearchableSelect.vue'
import AdditionalServiceCatalog from '@/components/billing/AdditionalServiceCatalog.vue'
import { activeInvoiceTypes, loadInvoiceTypes } from '@/utils/invoiceType'

const router = useRouter()
const toast = ref(null)

const tabs = [
    { key: 'catalogo', label: 'Catálogo' },
    { key: 'puntual',  label: 'Cargo puntual' },
]

// Arranca en el catálogo: es el propósito principal del módulo (los servicios
// que se cobran mes a mes). El cargo puntual queda a un clic.
const tab = ref('catalogo')

const onNotify = ({ type, title, message }) => {
    toast.value?.[type === 'error' ? 'error' : 'success'](title, message)
}
const submitting = ref(false)
const customers = ref([])

const typeOptions = computed(() => activeInvoiceTypes())

const defaultItem = () => ({ description: '', quantity: 1, unit_price: 0, type: '' })

const form = ref({
    customer_id: '',
    invoice_type: 'additional',
    items: [defaultItem()],
    due_date: new Date(Date.now() + 5 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    notes: '',
})

/**
 * Historial de esta pantalla.
 *
 * Un cargo adicional es una factura con su `invoice_type`, así que el listado
 * ya existe: Facturación. En vez de mantener aquí una tabla paralela —con sus
 * propios filtros, totales y exportación, condenada a divergir— se abre esa
 * pantalla acotada al tipo que está seleccionado en el formulario.
 */
const verCargosGenerados = () => {
    router.push({
        path: '/billing/invoices',
        // `period: ''` deja ver los de todos los meses: con el mes actual por
        // defecto, un cargo del mes pasado no aparecería y parecería que no hay.
        query: { invoice_type: form.value.invoice_type || 'additional', period: '' },
    })
}

const addItem = () => form.value.items.push(defaultItem())
const removeItem = (i) => form.value.items.splice(i, 1)

const total = computed(() =>
    form.value.items.reduce((sum, item) => sum + (item.quantity || 0) * (item.unit_price || 0), 0)
)

const customerLabel = (c) => `${c.name} ${c.last_name}`

const selectedCustomerName = computed(() => {
    if (!form.value.customer_id) return ''
    const c = customers.value.find(c => c.user_id === form.value.customer_id)
    return c ? customerLabel(c) : ''
})


const isFormValid = computed(() => {
    if (!form.value.customer_id) return false
    return form.value.items.every(i => i.description.trim() && i.quantity > 0 && i.unit_price >= 0)
})

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

const loadCustomers = async () => {
    try {
        const res = await api.customers.getAll()
        customers.value = res.data.data || res.data
    } catch (e) {
        console.error(e)
    }
}

const submitCharge = async () => {
    if (!isFormValid.value) return
    try {
        submitting.value = true
        const payload = {
            customer_id: form.value.customer_id,
            invoice_type: form.value.invoice_type || undefined,
            items: form.value.items.map(i => ({
                description: i.description,
                quantity: i.quantity,
                unit_price: i.unit_price,
                type: i.type || undefined,
            })),
            due_date: form.value.due_date || undefined,
            notes: form.value.notes || undefined,
        }
        const res = await billingService.storeAdditionalCharge(payload)
        toast.value?.success('Cargo generado', res.data.message || 'El cargo adicional fue creado correctamente.')
        form.value = {
            customer_id: '',
            invoice_type: form.value.invoice_type,
            items: [defaultItem()],
            due_date: new Date(Date.now() + 5 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            notes: '',
        }
        if (res.data.invoice?.id) {
            setTimeout(() => router.push(`/billing/invoices/${res.data.invoice.id}`), 1200)
        }
    } catch (e) {
        const msg = e.response?.data?.message || 'No se pudo generar el cargo.'
        toast.value?.error('Error', msg)
    } finally {
        submitting.value = false
    }
}

onMounted(() => {
    loadCustomers()
    loadInvoiceTypes()
})
</script>
