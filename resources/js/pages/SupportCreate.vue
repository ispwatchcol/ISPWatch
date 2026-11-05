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
                            <!-- PR #6 · § 6: el operador sólo elige Individual o
                                 Masivo. Un masivo es el incidente padre: no tiene
                                 cliente, tiene el elemento de red afectado, y los
                                 clientes que llamen se le ASOCIAN. -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Modalidad</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="m in MODALIDADES"
                                        :key="m.value"
                                        type="button"
                                        @click="form.modality = m.value"
                                        class="rounded-lg border px-3 py-2 text-left text-sm transition"
                                        :class="form.modality === m.value
                                            ? 'border-blue-500 bg-blue-50 text-blue-800 dark:border-blue-400 dark:bg-blue-900/30 dark:text-blue-200'
                                            : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'"
                                    >
                                        <span class="block font-semibold">{{ m.label }}</span>
                                        <span class="block text-xs opacity-80">{{ m.ayuda }}</span>
                                    </button>
                                </div>
                            </div>

                            <div v-if="esMasivo">
                                <SearchableSelect
                                    v-if="!loadingElementos && !errorElementos"
                                    v-model="form.sectorial_id"
                                    :items="elementos"
                                    item-key="id"
                                    :item-label="elementoLabel"
                                    item-icon="bi-diagram-3"
                                    label="Elemento de red afectado"
                                    placeholder="-- AP, nodo, OLT, NAP… --"
                                    search-placeholder="Buscar elemento..."
                                    :required="true"
                                    :error="errors.sectorial_id"
                                />
                                <p v-else-if="errorElementos" class="text-sm text-red-600 dark:text-red-400">
                                    No se pudo cargar la lista de elementos de red. Hace falta acceso a Sectoriales.
                                </p>
                                <p v-else class="text-sm text-gray-500 dark:text-gray-400">Cargando elementos de red...</p>
                            </div>

                            <div v-if="!esMasivo">
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

                                <!-- PR #6 · Prevención de duplicados (§ 16): antes de
                                     radicar, lo que el cliente ya tiene abierto y las
                                     caídas activas sobre su infraestructura. Avisa,
                                     no bloquea: puede ser otra falla. -->
                                <div
                                    v-if="relacionados.open_tickets.length || relacionados.active_incidents.length"
                                    class="mt-3 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-900/20"
                                >
                                    <p v-if="relacionados.open_tickets.length" class="font-semibold text-amber-900 dark:text-amber-200">
                                        Este cliente ya tiene {{ relacionados.open_tickets.length }} ticket(s) abierto(s):
                                    </p>
                                    <ul v-if="relacionados.open_tickets.length" class="space-y-1">
                                        <li v-for="t in relacionados.open_tickets" :key="t.id">
                                            <router-link :to="`/support/${t.id}`" target="_blank" class="text-amber-800 underline hover:text-amber-950 dark:text-amber-300">
                                                #{{ t.id }} · {{ t.subject }}
                                            </router-link>
                                            <span class="text-xs text-amber-700 dark:text-amber-400"> — {{ t.status_label }} · {{ fechaBogota(t.created_at) }}</span>
                                        </li>
                                    </ul>
                                    <p v-if="relacionados.active_incidents.length" class="font-semibold text-amber-900 dark:text-amber-200">
                                        Hay {{ relacionados.active_incidents.length }} incidente(s) masivo(s) activo(s) en su infraestructura:
                                    </p>
                                    <ul v-if="relacionados.active_incidents.length" class="space-y-1">
                                        <li v-for="i in relacionados.active_incidents" :key="i.id">
                                            <router-link :to="`/support/${i.id}`" target="_blank" class="text-amber-800 underline hover:text-amber-950 dark:text-amber-300">
                                                #{{ i.id }} · {{ i.subject }}
                                            </router-link>
                                            <span class="text-xs text-amber-700 dark:text-amber-400">
                                                — {{ i.sectorial?.name || 'sin elemento' }} · {{ i.children_count }} asociado(s)
                                            </span>
                                        </li>
                                    </ul>
                                    <p class="text-xs text-amber-700 dark:text-amber-400">
                                        Si es la misma falla, crea el ticket y desde su detalle márcalo como duplicado o asócialo al incidente.
                                    </p>
                                </div>
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
                            <!-- Del catálogo de asuntos del ISP, o «Otro…» con texto
                                 libre. Del catálogo es lo que permite filtrar después. -->
                            <TicketSubjectField
                                v-model="asunto"
                                :error="errors.subject || errors.subject_id"
                                :puede-administrar="canManageSubjects"
                            />

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

                    <!-- Card: Diagnóstico técnico (PR #2) -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6">
                        <TicketDiagnosisFields
                            v-model="diagnostico"
                            :errores="errors"
                            :cargando="!catalogosCargados && !catalogosConError"
                            @reintentar="cargarCatalogos(true)"
                        />
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
                            <label class="relative inline-flex items-center" :class="noCharge ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'">
                                <input v-model="addCharge" :disabled="noCharge" type="checkbox" class="sr-only peer" />
                                <div class="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Activa esta opción si la visita requiere un cobro (visita técnica, materiales, etc.)</p>

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

                        <div v-if="asuntoTexto" class="mb-4 p-3 bg-gray-50 dark:bg-gray-700/40 rounded-lg">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Asunto</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-white">{{ asuntoTexto }}</p>
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

                        <div v-if="!selectedCustomer && !asuntoTexto" class="text-center py-6 text-gray-400 dark:text-gray-500 text-sm italic">
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
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import NotificationToast from '../components/NotificationToast.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import TicketDiagnosisFields from '../components/TicketDiagnosisFields.vue'
import TicketSubjectField from '../components/TicketSubjectField.vue'
import { useTicketCatalogs } from '@/composables/useTicketCatalogs'
import { useAuthStore } from '../stores/auth'

const router = useRouter()

// PR #2 — el vocabulario del Anexo A para los desplegables de diagnóstico.
const {
    cargado: catalogosCargados,
    error: catalogosConError,
    cargar: cargarCatalogos,
} = useTicketCatalogs()

const form = ref({
    modality: 'individual',
    user_id: '',
    sectorial_id: '',
    staff_id: '',
    description: ''
})

// PR #6 · § 6 de la Solicitud Maestra.
const MODALIDADES = [
    { value: 'individual', label: 'Individual', ayuda: 'Un servicio, un cliente' },
    { value: 'masivo', label: 'Masivo', ayuda: 'Caída de AP, nodo, OLT…' },
]
const esMasivo = computed(() => form.value.modality === 'masivo')

// Elementos de red: sólo se cargan si se elige «Masivo».
const elementos = ref([])
const loadingElementos = ref(false)
const errorElementos = ref(false)
const elementoLabel = (e) => e.element_type ? `${e.name} (${e.element_type})` : e.name

const loadElementos = async () => {
    if (elementos.value.length || loadingElementos.value) return
    try {
        loadingElementos.value = true
        errorElementos.value = false
        const { data } = await api.sectorials.getAll()
        elementos.value = data.data || data
    } catch (err) {
        console.error('Error al cargar elementos de red:', err)
        errorElementos.value = true
    } finally {
        loadingElementos.value = false
    }
}

watch(esMasivo, (masivo) => {
    if (masivo) {
        form.value.user_id = ''
        loadElementos()
    } else {
        form.value.sectorial_id = ''
    }
})

// PR #6 · Tickets abiertos e incidentes activos del cliente elegido.
const relacionados = ref({ open_tickets: [], active_incidents: [] })
let consultaRelacionados = 0

watch(() => form.value.user_id, async (userId) => {
    const turno = ++consultaRelacionados
    relacionados.value = { open_tickets: [], active_incidents: [] }
    if (!userId) return
    try {
        const { data } = await api.support.openFor({ user_id: userId })
        // Si el operador cambió de cliente mientras llegaba la respuesta, se
        // descarta: el aviso tiene que ser del cliente que está en pantalla.
        if (turno === consultaRelacionados) {
            relacionados.value = {
                open_tickets: data.open_tickets ?? [],
                active_incidents: data.active_incidents ?? [],
            }
        }
    } catch (err) {
        // El aviso es una ayuda: si falla, se puede radicar igual.
        console.error('Error al buscar tickets abiertos del cliente:', err)
    }
})

const fechaBogota = (iso) => iso
    ? new Date(iso).toLocaleString('es-CO', { timeZone: 'America/Bogota', dateStyle: 'short', timeStyle: 'short' })
    : ''

// Asunto: `{ subject_id, subject }`. Ver TicketSubjectField.
const asunto = ref({ subject_id: null, subject: '' })
const asuntoTexto = computed(() => asunto.value.subject?.trim() || '')

const authStore = useAuthStore()
const canManageSubjects = computed(() => authStore.hasPermission('ticket_manage_catalogs'))

// Lo habitual es abrir el ticket sin diagnóstico y completarlo tras la visita,
// pero quien atiende por teléfono a veces ya sabe el síntoma. Se ofrece, no se
// exige: las reglas de obligatoriedad son del PR #4.
const diagnostico = ref({
    symptom: null,
    suspected_cause: null,
    confirmed_cause: null,
    solution: null,
    result: null,
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

// Visita que no se le cobra al cliente. Apaga el cargo de este formulario y
// deja el ticket cerrado a cargos futuros.
const noCharge = ref(false)
const noChargeReason = ref('')
const onNoChargeToggle = () => {
    if (noCharge.value) addCharge.value = false
}
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
    if (!esMasivo.value && !form.value.user_id) errors.value.user_id = 'El cliente es requerido'
    if (esMasivo.value && !form.value.sectorial_id) errors.value.sectorial_id = 'Elige el elemento de red afectado'
    if (!asunto.value.subject_id && !asuntoTexto.value) errors.value.subject = 'Elige un asunto o escribe uno'
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

        // Sólo se envían los campos de diagnóstico REALMENTE elegidos: mandar
        // los cinco en null obligaría al backend a distinguir «no diagnosticado»
        // de «diagnosticado y borrado» en un alta, donde no hay nada que borrar.
        const diagnosticoElegido = Object.fromEntries(
            Object.entries(diagnostico.value).filter(([, code]) => code),
        )

        const ticketRes = await api.support.create({
            modality: form.value.modality,
            // Un masivo no lleva cliente (el servidor lo rechaza) y sí elemento.
            ...(esMasivo.value
                ? { sectorial_id: form.value.sectorial_id }
                : { user_id: form.value.user_id }),
            staff_id: form.value.staff_id || null,
            // Con asunto del catálogo el servidor copia su nombre; con «Otro…»
            // manda el texto. Se envían los dos y el servidor decide.
            subject_id: asunto.value.subject_id || null,
            subject: asuntoTexto.value || null,
            description: form.value.description,
            no_charge: noCharge.value,
            no_charge_reason: noChargeReason.value || null,
            ...diagnosticoElegido,
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
