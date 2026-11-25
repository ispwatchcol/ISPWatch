<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <NotificationToast ref="toast" />

        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Nuevo ticket</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Quién llama, qué le pasa y quién lo atiende. Lo demás se completa después.</p>
                </div>
                <button @click="router.push('/support')" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition flex items-center gap-2">
                    <v-icon name="ri-arrow-go-back-line" class="w-4 h-4" />
                    Volver
                </button>
            </div>

            <form @submit.prevent="handleSubmit" class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Columna principal -->
                <div class="lg:col-span-2 space-y-5">
                    <!-- 1 · Quién -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 sm:p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="paso">1</span>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">¿Quién es el cliente?</h2>
                        </div>

                        <SearchableSelect
                            v-if="!loadingCustomers"
                            v-model="form.user_id"
                            :items="customers"
                            item-key="user_id"
                            :item-label="customerLabel"
                            :item-sublabel="customerSublabel"
                            :item-search="customerSearch"
                            item-icon="bi-person"
                            placeholder="-- Selecciona un cliente --"
                            search-placeholder="Nombre, cédula, teléfono, dirección o IP…"
                            :required="true"
                            :error="errors.user_id"
                            size="lg"
                        />
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400">Cargando clientes...</p>

                        <!-- Ficha del cliente elegido: estado del servicio, dónde
                             es y —lo más importante— si ya tiene un ticket abierto. -->
                        <TicketCustomerCard
                            v-if="form.user_id"
                            :user-id="form.user_id"
                            titulo="Ficha del cliente"
                            compacto
                            class="mt-4 !shadow-none border border-gray-200 dark:border-gray-700"
                        />
                    </div>

                    <!-- 2 · Qué -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 sm:p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="paso">2</span>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">¿Qué le pasa?</h2>
                        </div>

                        <div class="space-y-4">
                            <!-- Los asuntos más usados, a un toque. -->
                            <div v-if="asuntosFrecuentes.length" class="flex flex-wrap gap-2" data-testid="frequent-subjects">
                                <button
                                    v-for="s in asuntosFrecuentes"
                                    :key="s.id"
                                    type="button"
                                    @click="asunto = { subject_id: s.id, subject: s.name }"
                                    class="rounded-full border px-3 py-1.5 text-sm transition"
                                    :class="asunto.subject_id === s.id
                                        ? 'border-blue-600 bg-blue-600 text-white'
                                        : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400 hover:bg-blue-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'"
                                >
                                    {{ s.name }}
                                </button>
                            </div>

                            <TicketSubjectField
                                v-model="asunto"
                                :error="errors.subject || errors.subject_id"
                                :puede-administrar="canManageSubjects"
                            />

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Descripción</label>
                                <textarea
                                    v-model="form.description"
                                    rows="3"
                                    placeholder="Lo que cuenta el cliente: desde cuándo, qué luces ve el equipo, si ya reinició…"
                                    class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                ></textarea>
                            </div>

                            <!-- Sólo el síntoma: quien recibe la llamada todavía
                                 no sabe la causa ni la solución. Eso se registra
                                 al cerrar, en la misma ventana. -->
                            <TicketDiagnosisFields
                                v-if="canDiagnose"
                                v-model="diagnostico"
                                :solo-campos="['symptom']"
                                titulo="Síntoma (opcional)"
                                ayuda="Si ya sabes qué reporta el cliente. La causa y la solución se registran al cerrar."
                                :errores="errors"
                                :cargando="!catalogosCargados && !catalogosConError"
                                @reintentar="cargarCatalogos(true)"
                            />
                        </div>
                    </div>

                    <!-- 3 · Quién lo atiende -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 sm:p-6">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="paso">3</span>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-white">Prioridad y técnico</h2>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Prioridad</p>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="priority-buttons">
                                    <button
                                        v-for="p in PRIORIDADES"
                                        :key="p.code"
                                        type="button"
                                        :disabled="!canSetPriority && p.code !== 'medium'"
                                        @click="form.priority = p.code"
                                        class="rounded-lg border px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40"
                                        :class="form.priority === p.code ? p.activo : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'"
                                    >
                                        {{ etiquetaPrioridad(p) }}
                                    </button>
                                </div>
                                <p v-if="!canSetPriority" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Cambiar la prioridad exige el permiso «Tickets · cambiar prioridad».
                                </p>
                            </div>

                            <div>
                                <SearchableSelect
                                    v-if="!loadingStaff"
                                    v-model="form.staff_id"
                                    :items="staff"
                                    item-key="id"
                                    :item-label="staffLabel"
                                    item-sublabel="role_name"
                                    item-icon="md-supportagent-round"
                                    label="Técnico asignado"
                                    placeholder="Sin asignar"
                                    search-placeholder="Buscar técnico..."
                                    :clearable="true"
                                    clear-label="Sin asignar"
                                    :disabled="!canAssign"
                                />
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">Cargando personal...</p>
                                <p v-if="form.staff_id && !esModoSimple()" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Con técnico, el ticket queda directamente <strong>Asignado</strong>.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Cobro (opcional) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 sm:p-6">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                    <v-icon name="la-dollar-sign-solid" class="w-4 h-4 text-green-600 dark:text-green-400" />
                                </div>
                                <h2 class="text-lg font-bold text-gray-800 dark:text-white">Cobro <span class="text-sm font-normal text-gray-500">(opcional)</span></h2>
                            </div>
                            <label class="relative inline-flex items-center" :class="noCharge ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'">
                                <input v-model="addCharge" :disabled="noCharge" type="checkbox" class="sr-only peer" />
                                <div class="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Actívalo si la visita lleva un cobro (visita técnica, materiales…). También se puede cobrar después, desde el ticket.</p>

                        <!-- Sin cobro: no es «hoy no lo cobro», es «este ticket no
                             se puede cobrar». Cierra también el cargo posterior. -->
                        <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-3">
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input v-model="noCharge" @change="onNoChargeToggle" type="checkbox"
                                    class="mt-0.5 w-4 h-4 rounded border-gray-300 dark:border-gray-600 text-amber-600 focus:ring-amber-500" />
                                <span>
                                    <span class="text-sm font-medium text-amber-900 dark:text-amber-200">Sin cobro al cliente</span>
                                    <span class="block text-xs text-amber-700 dark:text-amber-300 mt-0.5">
                                        Mantenimiento o garantía. Los equipos que se entreguen salen igual del inventario
                                        y son gasto de la empresa, pero este ticket no podrá generar cargos.
                                    </span>
                                </span>
                            </label>
                            <input v-if="noCharge" v-model="noChargeReason" type="text" maxlength="255"
                                placeholder="Motivo (opcional): garantía, daño por rayo, retención…"
                                class="mt-2 w-full bg-white dark:bg-gray-800 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500" />
                        </div>

                        <div v-if="addCharge && !noCharge" class="space-y-3 animate-fadeIn">
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

                                <div class="mb-3 pr-8">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Descripción</label>
                                    <input
                                        v-model="item.description"
                                        type="text"
                                        placeholder="Ej: Cambio de antena, Cable UTP, Visita técnica…"
                                        class="w-full px-3 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500"
                                    />
                                </div>

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
                <div class="space-y-5">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-5 sm:p-6 lg:sticky lg:top-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
                            <v-icon name="md-description" class="w-5 h-5 text-gray-500" />
                            Resumen
                        </h3>

                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Cliente</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white">
                                    {{ selectedCustomer ? `${selectedCustomer.name} ${selectedCustomer.last_name}` : '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Asunto</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white">{{ asuntoTexto || '—' }}</dd>
                            </div>
                            <div class="flex gap-6">
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Prioridad</dt>
                                    <dd><span :class="priorityBadgeClass(form.priority)">{{ priorityLabel(form.priority) || form.priority }}</span></dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">Técnico</dt>
                                    <dd class="font-medium text-gray-800 dark:text-white">
                                        {{ selectedStaff ? `${selectedStaff.user_name} ${selectedStaff.user_lastname}` : 'Sin asignar' }}
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <div v-if="addCharge && !noCharge" class="mt-4 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-900/30">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Cobro</p>
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

                        <div class="space-y-2 mt-6">
                            <button
                                type="submit"
                                :disabled="submitting"
                                data-testid="create-ticket-submit"
                                class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition disabled:opacity-50 flex items-center justify-center gap-2"
                            >
                                <div v-if="submitting" class="animate-spin rounded-full h-5 w-5 border-2 border-white border-t-transparent"></div>
                                <v-icon v-else name="md-check" class="w-5 h-5" />
                                {{ submitting ? 'Creando...' : 'Crear ticket' }}
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
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import NotificationToast from '../components/NotificationToast.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import TicketDiagnosisFields from '../components/TicketDiagnosisFields.vue'
import TicketSubjectField from '../components/TicketSubjectField.vue'
import TicketCustomerCard from '../components/support/TicketCustomerCard.vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import { useAuthStore } from '../stores/auth'
import { priorityBadgeClass } from '@/utils/ticketStyles'

const router = useRouter()
const route = useRoute()

const {
    cargado: catalogosCargados,
    error: catalogosConError,
    cargar: cargarCatalogos,
    priorityLabel,
    esModoSimple,
} = useTicketCatalogs()

const form = ref({
    user_id: '',
    staff_id: '',
    description: '',
    priority: 'medium',
})

// Mientras el catálogo no llega, `priorityLabel` devuelve el código («high»):
// se muestra el nombre fijo hasta entonces.
const etiquetaPrioridad = (p) => {
    const l = priorityLabel(p.code)
    return l && l !== p.code ? l : p.label
}

const PRIORIDADES = [
    { code: 'low', label: 'Baja', activo: 'border-gray-500 bg-gray-600 text-white' },
    { code: 'medium', label: 'Media', activo: 'border-blue-600 bg-blue-600 text-white' },
    { code: 'high', label: 'Alta', activo: 'border-orange-500 bg-orange-500 text-white' },
    { code: 'urgent', label: 'Urgente', activo: 'border-red-600 bg-red-600 text-white' },
]

// Asunto: `{ subject_id, subject }`. Ver TicketSubjectField.
const asunto = ref({ subject_id: null, subject: '' })
const asuntoTexto = computed(() => asunto.value.subject?.trim() || '')

const authStore = useAuthStore()
const canManageSubjects = computed(() => authStore.hasPermission('ticket_manage_catalogs'))
const canSetPriority = computed(() => authStore.hasPermission('ticket_set_priority'))
const canAssign = computed(() => authStore.hasPermission('ticket_assign') || authStore.hasPermission('ticket_create'))
const canDiagnose = computed(() => authStore.hasPermission('ticket_diagnose'))

// En el alta sólo se ofrece el síntoma. El resto del diagnóstico se registra
// al cerrar (bitácora § 133): quien atiende la llamada no lo sabe todavía.
const diagnostico = ref({ symptom: null })

const customers = ref([])
const loadingCustomers = ref(false)
const staff = ref([])
const loadingStaff = ref(false)
const errors = ref({})
const submitting = ref(false)
const toast = ref(null)

// Los asuntos más usados del ISP, como botones rápidos.
const asuntosFrecuentes = ref([])

// Cargo opcional
const addCharge = ref(false)
const noCharge = ref(false)
const noChargeReason = ref('')
const onNoChargeToggle = () => {
    if (noCharge.value) addCharge.value = false
}
const unitOptions = ['Unidad', 'Metros', 'Horas', 'Kit', 'Servicio', 'Kg']
const chargeItems = ref([{ description: '', quantity: 1, unit: 'Unidad', unit_price: 0 }])
const chargeDueDate = ref(new Date(Date.now() + 5 * 24 * 60 * 60 * 1000).toISOString().split('T')[0])

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

const customerLabel = (c) => `${c.name} ${c.last_name}`.trim()
// Lo que distingue a dos «Juan Pérez»: cédula, dirección, plan; y si ya
// tiene tickets abiertos, se dice desde la lista.
const customerSublabel = (c) => [
    c.cedula ? `CC ${c.cedula}` : null,
    c.address,
    c.plan_name,
    c.service_status === 'suspendido' ? 'CORTADO' : null,
    c.open_tickets ? `${c.open_tickets} ticket${c.open_tickets === 1 ? '' : 's'} abierto${c.open_tickets === 1 ? '' : 's'}` : null,
].filter(Boolean).join(' · ')
const customerSearch = (c) => [c.tel, c.ip_user, c.city].filter(Boolean).join(' ')
const staffLabel = (s) => `${s.user_name} ${s.user_lastname}`

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

const validate = () => {
    errors.value = {}
    if (!form.value.user_id) errors.value.user_id = 'El cliente es requerido'
    if (!asunto.value.subject_id && !asuntoTexto.value) errors.value.subject = 'Elige un asunto o escribe uno'
    return Object.keys(errors.value).length === 0
}

const handleSubmit = async () => {
    if (!validate()) {
        toast.value?.error('Falta información', 'Elige el cliente y el asunto.')
        return
    }
    if (addCharge.value && !isChargeValid.value) {
        toast.value?.error('Error', 'Completa todos los ítems del cobro (descripción, cantidad y precio).')
        return
    }

    try {
        submitting.value = true

        const ticketRes = await api.support.create({
            user_id: form.value.user_id,
            staff_id: form.value.staff_id || null,
            subject_id: asunto.value.subject_id || null,
            subject: asuntoTexto.value || null,
            description: form.value.description,
            priority: form.value.priority,
            no_charge: noCharge.value,
            no_charge_reason: noChargeReason.value || null,
            ...(diagnostico.value.symptom ? { symptom: diagnostico.value.symptom } : {}),
        })

        const newTicketId = ticketRes.data?.id || ticketRes.data?.ticket?.id

        if (addCharge.value && !noCharge.value && newTicketId) {
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
                toast.value?.success('Éxito', 'Ticket y cobro creados correctamente.')
            } catch (chargeErr) {
                console.error('Error al generar cargo:', chargeErr)
                toast.value?.error('Cobro no creado', 'El ticket se creó pero no se pudo generar el cobro. Puedes cobrarlo desde el ticket.')
            }
        } else {
            toast.value?.success('Éxito', 'Ticket creado correctamente.')
        }

        setTimeout(() => router.push(newTicketId ? `/support/${newTicketId}` : '/support'), 900)
    } catch (err) {
        console.error('Error al crear ticket:', err)
        if (err.response?.data?.errors) {
            errors.value = err.response.data.errors
            toast.value?.error('Error', 'Por favor revisa los campos del formulario.')
        } else if (err.response?.status === 403) {
            toast.value?.error('Sin permiso', err.response?.data?.message || 'Tu rol no puede hacer eso.')
        } else {
            toast.value?.error('Error', 'No se pudo crear el ticket. Intenta de nuevo.')
        }
    } finally {
        submitting.value = false
    }
}

// Clientes del ISP para el buscador. Sale de `/support/customer-options` y no
// de `/customers`, que exige `view_clients`: quien radica no siempre lo tiene.
const loadCustomers = async () => {
    try {
        loadingCustomers.value = true
        const response = await api.support.getCustomerOptions()
        customers.value = response.data.data || []

        // «Nuevo ticket» desde la ficha del cliente llega con `?customer_id=`.
        // Antes se ignoraba y había que buscar otra vez al cliente.
        const preElegido = Number(route.query.customer_id || route.query.user_id)
        if (preElegido && customers.value.some(c => c.user_id === preElegido)) {
            form.value.user_id = preElegido
        }
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        toast.value?.error('Error', 'No se pudo cargar la lista de clientes.')
    } finally {
        loadingCustomers.value = false
    }
}

// Técnico = quien puede registrar intervenciones (`can_intervene`, P-56).
const loadStaff = async () => {
    try {
        loadingStaff.value = true
        const staffRes = await api.support.getStaffOptions()
        const allUsers = staffRes.data.data || []
        staff.value = allUsers.filter(user => user.can_intervene)
    } catch (err) {
        console.error('Error al cargar personal:', err)
        toast.value?.error('Error', 'Error al cargar la lista del personal.')
    } finally {
        loadingStaff.value = false
    }
}

const loadFrequentSubjects = async () => {
    try {
        const { data } = await api.support.getSubjects()
        asuntosFrecuentes.value = (data || [])
            .filter(s => s.is_active !== false)
            .sort((a, b) => (b.usage || 0) - (a.usage || 0) || (a.sort_order || 0) - (b.sort_order || 0))
            .slice(0, 6)
    } catch {
        // Sin botones rápidos se sigue pudiendo elegir del desplegable.
    }
}

onMounted(() => {
    loadCustomers()
    loadStaff()
    loadFrequentSubjects()
    cargarCatalogos()
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
.paso {
    @apply flex h-7 w-7 items-center justify-center rounded-full bg-blue-600 text-sm font-bold text-white;
}
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
