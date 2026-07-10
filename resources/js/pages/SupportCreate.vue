<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <NotificationToast ref="toast" />

        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo Ticket de Soporte</h1>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">Registra un nuevo ticket y opcionalmente un cargo asociado.</p>
                </div>
                <button @click="router.push('/support')" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition flex items-center gap-2">
                    <v-icon name="ri-arrow-go-back-line" class="w-4 h-4" />
                    Volver
                </button>
            </div>

            <form @submit.prevent="handleSubmit" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Columna principal -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Card: Asignación -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                                <v-icon name="bi-person" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                            </div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">Asignación</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <SearchableSelect
                                    v-if="!loadingCustomers"
                                    v-model="form.user_id"
                                    :items="customers"
                                    item-key="user_id"
                                    :item-label="customerLabel"
                                    item-icon="bi-person"
                                    label="Cliente"
                                    placeholder="-- Selecciona un cliente --"
                                    search-placeholder="Buscar por nombre..."
                                    :required="true"
                                    :error="errors.user_id"
                                />
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">Cargando clientes...</p>
                            </div>

                            <div>
                                <SearchableSelect
                                    v-if="!loadingStaff"
                                    v-model="form.staff_id"
                                    :items="staff"
                                    item-key="id"
                                    :item-label="staffLabel"
                                    item-icon="md-supportagent-round"
                                    label="Técnico asignado"
                                    placeholder="Sin asignar"
                                    search-placeholder="Buscar técnico..."
                                    :clearable="true"
                                    clear-label="Sin asignar"
                                />
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">Cargando personal...</p>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Detalles del ticket -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                                <v-icon name="hi-ticket" class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            </div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">Detalles del Ticket</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Asunto <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="form.subject"
                                    type="text"
                                    placeholder="Ej: Problema con conexión a internet"
                                    class="w-full px-3 py-2 rounded-lg border bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    :class="errors.subject ? 'border-red-500' : 'border-gray-300 dark:border-gray-600'"
                                />
                                <p v-if="errors.subject" class="mt-1 text-sm text-red-500">{{ errors.subject }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Descripción</label>
                                <textarea
                                    v-model="form.description"
                                    rows="4"
                                    placeholder="Describe el problema en detalle..."
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Cargo opcional -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                    <v-icon name="la-dollar-sign-solid" class="w-4 h-4 text-green-600 dark:text-green-400" />
                                </div>
                                <h2 class="text-lg font-bold text-gray-800 dark:text-white">Cargo Asociado</h2>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input v-model="addCharge" type="checkbox" class="sr-only peer" />
                                <div class="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Activa esta opción si la visita requiere un cobro (visita técnica, materiales, etc.)</p>

                        <div v-if="addCharge" class="space-y-3 animate-fadeIn">
                            <!-- Unidades sugeridas (el usuario también puede escribir una propia) -->
                            <datalist id="charge-unit-options">
                                <option v-for="u in unitOptions" :key="u" :value="u" />
                            </datalist>

                            <div v-for="(item, idx) in chargeItems" :key="idx" class="relative p-4 bg-green-50 dark:bg-green-900/10 rounded-lg border border-green-200 dark:border-green-900/30">
                                <button
                                    v-if="chargeItems.length > 1"
                                    type="button"
                                    @click="chargeItems.splice(idx, 1)"
                                    title="Eliminar ítem"
                                    class="absolute top-2 right-2 p-1.5 rounded-md text-red-400 hover:text-red-600 hover:bg-red-100 dark:hover:bg-red-900/30 transition"
                                >
                                    <v-icon name="md-delete" class="w-4 h-4" />
                                </button>

                                <!-- Descripción (fila completa) -->
                                <div class="mb-3 pr-8">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Descripción</label>
                                    <input
                                        v-model="item.description"
                                        type="text"
                                        placeholder="Ej: Cambio de antena, Cable UTP, Visita técnica…"
                                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                    />
                                </div>

                                <!-- Cantidad · Unidad · Precio (fila separada, con aire) -->
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Cantidad</label>
                                        <input
                                            v-model.number="item.quantity"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            onwheel="this.blur()"
                                            class="charge-num w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unidad</label>
                                        <input
                                            v-model.trim="item.unit"
                                            list="charge-unit-options"
                                            type="text"
                                            placeholder="Unidad"
                                            class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                    <div class="col-span-2 sm:col-span-1">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Precio unitario</label>
                                        <input
                                            v-model.number="item.unit_price"
                                            type="number"
                                            min="0"
                                            step="1"
                                            onwheel="this.blur()"
                                            class="charge-num w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                        />
                                    </div>
                                </div>

                                <!-- Resumen de la línea -->
                                <div class="flex flex-wrap items-center justify-between gap-x-2 mt-3 pt-2 border-t border-green-200/60 dark:border-green-900/30 text-xs text-gray-600 dark:text-gray-300">
                                    <span>{{ formatQuantity(item) }} × {{ formatCurrency(item.unit_price) }}</span>
                                    <span>Subtotal: <strong class="text-gray-800 dark:text-white">{{ formatCurrency((item.quantity || 0) * (item.unit_price || 0)) }}</strong></span>
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="chargeItems.push({ description: '', quantity: 1, unit: 'Unidad', unit_price: 0 })"
                                class="text-sm text-green-700 dark:text-green-400 hover:text-green-900 flex items-center gap-1"
                            >
                                <v-icon name="md-add" class="w-4 h-4" />
                                Agregar otro ítem
                            </button>

                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Fecha de vencimiento</label>
                                <input
                                    v-model="chargeDueDate"
                                    type="date"
                                    class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar: Resumen -->
                <div class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 sticky top-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
                            <v-icon name="md-description" class="w-5 h-5 text-gray-500" />
                            Resumen
                        </h3>

                        <div v-if="selectedCustomer" class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cliente</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ selectedCustomer.name }} {{ selectedCustomer.last_name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ selectedCustomer.email }}</p>
                        </div>

                        <div v-if="selectedStaff" class="mb-4 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Técnico</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ selectedStaff.user_name }} {{ selectedStaff.user_lastname }}</p>
                        </div>

                        <div v-if="form.subject" class="mb-4 p-3 bg-gray-50 dark:bg-gray-700/40 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Asunto</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ form.subject }}</p>
                        </div>

                        <div v-if="addCharge" class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-900/30">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2 flex items-center gap-1">
                                <v-icon name="la-dollar-sign-solid" class="w-3 h-3" />
                                Cargo asociado
                            </p>
                            <div v-for="(item, i) in chargeItems" :key="i" class="flex justify-between text-xs mb-1">
                                <span class="text-gray-600 dark:text-gray-400 truncate max-w-[60%]">
                                    {{ item.description || `Ítem ${i + 1}` }}
                                    <span class="text-gray-400 dark:text-gray-500">({{ formatQuantity(item) }})</span>
                                </span>
                                <span class="text-gray-800 dark:text-white font-medium">{{ formatCurrency(item.quantity * item.unit_price) }}</span>
                            </div>
                            <div class="border-t border-green-200 dark:border-green-900/30 mt-2 pt-2 flex justify-between text-sm">
                                <strong class="text-gray-800 dark:text-white">Total</strong>
                                <strong class="text-green-700 dark:text-green-400">{{ formatCurrency(chargeTotal) }}</strong>
                            </div>
                        </div>

                        <div v-if="!selectedCustomer && !form.subject" class="text-center py-6 text-gray-400 dark:text-gray-500 text-sm italic">
                            Completa el formulario para ver el resumen
                        </div>

                        <div class="space-y-2 mt-6">
                            <button
                                type="submit"
                                :disabled="submitting"
                                class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition disabled:opacity-50 flex items-center justify-center gap-2"
                            >
                                <div v-if="submitting" class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                                <v-icon v-else name="md-check" class="w-5 h-5" />
                                {{ submitting ? 'Creando...' : 'Crear Ticket' }}
                            </button>
                            <button
                                type="button"
                                @click="router.push('/support')"
                                class="w-full py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition text-sm"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import NotificationToast from '../components/NotificationToast.vue'
import SearchableSelect from '../components/SearchableSelect.vue'

const router = useRouter()

const form = ref({
    user_id: '',
    staff_id: '',
    subject: '',
    description: ''
})

const customers = ref([])
const loadingCustomers = ref(false)
const staff = ref([])
const loadingStaff = ref(false)
const errors = ref({})
const submitting = ref(false)
const toast = ref(null)

// Cargo opcional
const addCharge = ref(false)
// Unidades sugeridas para la cantidad (el usuario puede escribir otra).
const unitOptions = ['Unidad', 'Metros', 'Horas', 'Kit', 'Servicio', 'Kg']
const chargeItems = ref([{ description: '', quantity: 1, unit: 'Unidad', unit_price: 0 }])
const chargeDueDate = ref(new Date(Date.now() + 5 * 24 * 60 * 60 * 1000).toISOString().split('T')[0])

// "12 Metros", "1 Servicio", "3" (sin unidad) — para el resumen de cada línea.
const formatQuantity = (item) => {
    const qty = item.quantity || 0
    const unit = (item.unit || '').trim()
    return unit ? `${qty} ${unit}` : `${qty}`
}

const chargeTotal = computed(() =>
    chargeItems.value.reduce((sum, i) => sum + (i.quantity || 0) * (i.unit_price || 0), 0)
)

const isChargeValid = computed(() =>
    chargeItems.value.every(i => i.description.trim() && i.quantity > 0 && i.unit_price >= 0)
)

const selectedCustomer = computed(() =>
    customers.value.find(c => c.user_id === form.value.user_id) || null
)

const selectedStaff = computed(() =>
    staff.value.find(s => s.id === form.value.staff_id) || null
)

const customerLabel = (c) => `${c.name} ${c.last_name}`
const staffLabel = (s) => `${s.user_name} ${s.user_lastname}`

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

const validate = () => {
    errors.value = {}
    if (!form.value.user_id) errors.value.user_id = 'El cliente es requerido'
    if (!form.value.subject || form.value.subject.trim() === '') errors.value.subject = 'El asunto es requerido'
    return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
    if (!validate()) return
    if (addCharge.value && !isChargeValid.value) {
        toast.value?.error('Error', 'Completa todos los ítems del cargo (descripción, cantidad y precio).')
        return
    }

    try {
        submitting.value = true

        const ticketRes = await api.support.create({
            user_id: form.value.user_id,
            staff_id: form.value.staff_id || null,
            subject: form.value.subject,
            description: form.value.description
        })

        const newTicketId = ticketRes.data?.id || ticketRes.data?.ticket?.id

        if (addCharge.value && newTicketId) {
            try {
                await api.support.generateCharge(newTicketId, {
                    items: chargeItems.value.map(i => ({
                        description: i.description,
                        quantity: i.quantity,
                        unit: (i.unit || '').trim() || undefined,
                        unit_price: i.unit_price,
                    })),
                    due_date: chargeDueDate.value || undefined,
                })
                toast.value?.success('Éxito', 'Ticket y cargo creados correctamente.')
            } catch (chargeErr) {
                console.error('Error al generar cargo:', chargeErr)
                toast.value?.error('Cargo no creado', 'El ticket se creó pero no se pudo generar el cargo.')
            }
        } else {
            toast.value?.success('Éxito', 'Ticket creado correctamente.')
        }

        setTimeout(() => router.push(newTicketId ? `/support/${newTicketId}` : '/support'), 1500)
    } catch (err) {
        console.error('Error al crear ticket:', err)
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors
            toast.value?.error('Error', 'Por favor revisa los campos del formulario.')
        } else {
            toast.value?.error('Error', 'No se pudo crear el ticket. Intenta de nuevo.')
        }
    } finally {
        submitting.value = false
    }
}

const loadCustomers = async () => {
    try {
        loadingCustomers.value = true
        const response = await api.customers.getAll()
        customers.value = response.data.data || response.data
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        toast.value?.error('Error', 'No se pudo cargar la lista de clientes.')
    } finally {
        loadingCustomers.value = false
    }
}

// Identifica al rol "Técnico" por NOMBRE (no por id): los roles son por tenant y
// el id del rol técnico varía entre tenants, por eso ya no sirve role_id === 5.
// Tolera acentos/mayúsculas: "Técnico" / "Tecnico" / "TÉCNICO".
const isTechnicianRole = (roleName) => {
    const n = (roleName || '').trim().toLowerCase()
    return n === 'técnico' || n === 'tecnico'
}

const loadStaff = async () => {
    try {
        loadingStaff.value = true
        const staffRes = await api.staff.getAll()
        const allUsers = staffRes.data.data || []
        staff.value = allUsers.filter(user => isTechnicianRole(user.role_name))
    } catch (err) {
        console.error('Error al cargar personal:', err)
        toast.value?.error('Error', 'Error al cargar la lista del personal.')
    } finally {
        loadingStaff.value = false
    }
}

onMounted(() => {
    loadCustomers()
    loadStaff()
})
</script>

<style scoped>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeIn {
    animation: fadeIn 0.2s ease-out;
}
/* Quita las flechas del input numérico: se encimaban con el valor ("remontado"). */
.charge-num::-webkit-outer-spin-button,
.charge-num::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.charge-num {
    -moz-appearance: textfield;
    appearance: textfield;
}
</style>
