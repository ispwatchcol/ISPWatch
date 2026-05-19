<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-3 sm:p-6">
        <NotificationToast ref="toast" />

        <!-- ── Confirm Dialog ─────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div v-if="confirmDialog.show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="cancelConfirm" />

                    <!-- Card -->
                    <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-100 dark:border-gray-700 p-6 max-w-md w-full">

                        <!-- Icon circle -->
                        <div :class="confirmIconBg" class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-5">
                            <v-icon :name="confirmDialog.icon" :class="confirmIconColor" class="w-7 h-7" />
                        </div>

                        <!-- Title -->
                        <h3 class="text-lg font-bold text-center text-gray-900 dark:text-white mb-2">
                            {{ confirmDialog.title }}
                        </h3>

                        <!-- Message -->
                        <p class="text-sm text-center text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                            {{ confirmDialog.message }}
                        </p>

                        <!-- Buttons -->
                        <div class="flex gap-3">
                            <button
                                @click="cancelConfirm"
                                class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-all text-sm"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="acceptConfirm"
                                :class="confirmBtnClass"
                                class="flex-1 px-4 py-2.5 rounded-xl text-white font-medium transition-all text-sm"
                            >
                                {{ confirmDialog.confirmLabel }}
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">Clientes</h1>
            <p class="text-sm sm:text-base text-gray-500 dark:text-gray-400 mt-1">Gestión de perfiles de clientes</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end">
            <!-- Export CSV -->
            <button
                @click="exportToCSV"
                class="text-sm bg-blue-50 text-blue-700 border border-blue-200 px-3 py-2 rounded-lg hover:bg-blue-100 transition-all flex items-center gap-2 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50"
                title="Exportar archivo CSV puro"
            >
                <icon-lucide-file-text class="w-4 h-4" />
                CSV
            </button>

            <!-- Export Excel -->
            <button
                @click="exportToExcel"
                class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-2 rounded-lg hover:bg-green-100 transition-all flex items-center gap-2 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50"
                title="Exportar archivo compatible con Excel"
            >
                <icon-lucide-file-spreadsheet class="w-4 h-4" />
                Excel
            </button>

            <button
                v-if="can('customers.create')"
                @click="router.push('/customers/create')"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition"
            >
                <v-icon name="bi-person-plus" class="w-5 h-5" />
                <span class="text-sm sm:text-base">Nuevo Cliente</span>
            </button>
        </div>
        </div>

        <!-- Search + Router filter + Provision -->
        <div class="mb-6 flex flex-col sm:flex-row gap-3 sm:gap-4 items-stretch sm:items-start">
            <!-- Search -->
            <div class="relative flex-1">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, email, IP..."
                    class="w-full bg-white dark:bg-gray-800 text-gray-800 dark:text-white px-4 py-2.5 sm:py-3 pl-11 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base"
                />
                <v-icon name="io-search" class="absolute left-3 top-2.5 sm:top-3.5 w-5 h-5 text-gray-400" />
                <button v-if="searchQuery" @click="searchQuery = ''"
                    class="absolute right-3 top-2 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <v-icon name="io-close-circle" class="w-6 h-6" />
                </button>
            </div>

            <!-- Router filter -->
            <div class="relative">
                <select
                    v-model="filterRouterId"
                    class="h-full appearance-none bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 pl-9 pr-8 py-2.5 sm:py-3 rounded-lg border border-gray-300 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm sm:text-base cursor-pointer"
                >
                    <option :value="null">Todos los routers</option>
                    <option v-for="r in availableRouters" :key="r.id" :value="r.id">
                        {{ r.name }}{{ r.pppoe ? ' · PPPoE' : '' }}
                    </option>
                </select>
                <icon-lucide-router class="absolute left-2.5 top-2.5 sm:top-3 w-4 h-4 text-gray-400 pointer-events-none" />
                <button v-if="filterRouterId" @click="filterRouterId = null"
                    class="absolute right-2 top-2.5 sm:top-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <v-icon name="io-close-circle" class="w-5 h-5" />
                </button>
            </div>

            <!-- Provision button -->
            <button
                @click="provisionCustomer"
                class="bg-green-600 hover:bg-green-700 text-white px-4 sm:px-6 py-2.5 sm:py-3 rounded-lg flex items-center justify-center gap-2 transition whitespace-nowrap text-sm sm:text-base"
                :title="`Provisionar ${filteredCustomers.length} cliente(s) al Router Board`"
            >
                <icon-lucide-server class="w-5 h-5" />
                <span>{{ provisionBtnLabel }}</span>
                <span v-if="selectedRouterInfo?.pppoe"
                    class="bg-white/20 text-white text-xs px-1.5 py-0.5 rounded-full font-medium leading-none">
                    PPPoE
                </span>
            </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">Cargando clientes...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            {{ error }}
        </div>

        <!-- Table / Cards -->
        <div v-else class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700">

            <!-- Desktop table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">#</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Nombre</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Apellido</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Email</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">IP</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Plan</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Sectorial</th>
                    <th class="px-6 py-4 text-left text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Router</th>
                    <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Estado</th>
                    <th class="px-6 py-4 text-center text-xs font-medium text-gray-600 dark:text-gray-300 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="(customer, idx) in filteredCustomers" :key="customer.user_id"
                        class="hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ idx + 1 }}</td>
                    <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-white">{{ customer.name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-800 dark:text-white">{{ customer.last_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.email }}</td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ customer.ip_user || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.service_name || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ customer.sectorial_name || '-' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                        <span>{{ customer.router_name || '-' }}</span>
                        <span v-if="customer.router_falla_general"
                            class="ml-1.5 inline-flex items-center gap-0.5 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-100 text-red-700 border border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800"
                            title="Router en falla general">
                            <v-icon name="md-warningamber" class="w-3 h-3" /> Falla
                        </span>
                        <span v-if="customer.router_pppoe && !customer.pppoe_username"
                            class="ml-1.5 inline-flex items-center gap-0.5 text-xs font-medium text-amber-600 dark:text-amber-400"
                            title="Router PPPoE sin credenciales guardadas — edita el cliente para configurarlas">
                            <v-icon name="md-warningamber" class="w-3.5 h-3.5" />PPPoE?
                        </span>
                        <span v-else-if="customer.router_pppoe && customer.pppoe_username"
                            class="ml-1.5 inline-flex items-center text-xs font-medium text-blue-500 dark:text-blue-400">
                            PPPoE
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span v-if="customer.status"
                            class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            Activo
                        </span>
                        <span v-else
                            class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            Suspendido
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex justify-center gap-2 flex-wrap">
                        <button v-if="can('customers.edit')" @click="router.push(`/customers/${customer.user_id}/edit`)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-blue-50 text-blue-700 border border-blue-200
                                hover:bg-blue-100 hover:scale-[1.03] transition-all
                                dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800 dark:hover:bg-blue-800/50">
                            <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                        </button>

                        <button v-if="can('customers.edit') && customer.status" @click="suspendCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-orange-50 text-orange-700 border border-orange-200
                                hover:bg-orange-100 hover:scale-[1.03] transition-all
                                dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800 dark:hover:bg-orange-800/50">
                            <icon-lucide-pause-circle class="w-3.5 h-3.5" /> Suspender
                        </button>

                        <button v-else-if="can('customers.edit') && !customer.status" @click="activateCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-green-50 text-green-700 border border-green-200
                                hover:bg-green-100 hover:scale-[1.03] transition-all
                                dark:bg-green-900/30 dark:text-green-300 dark:border-green-800 dark:hover:bg-green-800/50">
                            <icon-lucide-play-circle class="w-3.5 h-3.5" /> Activar
                        </button>

                        <button v-if="can('customers.delete')" @click="deleteCustomer(customer)"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg flex items-center gap-1
                                bg-red-50 text-red-700 border border-red-200
                                hover:bg-red-100 hover:scale-[1.03] transition-all
                                dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 dark:hover:bg-red-800/50">
                            <icon-lucide-trash-2 class="w-3.5 h-3.5" /> Eliminar
                        </button>
                        </div>
                    </td>
                    </tr>

                    <tr v-if="filteredCustomers.length === 0">
                    <td colspan="10" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        {{ searchQuery ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                    </td>
                    </tr>
                </tbody>
                </table>
            </div>

            <!-- Mobile cards -->
            <div class="md:hidden divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="customer in filteredCustomers" :key="customer.user_id" class="p-4">
                <div class="space-y-3">
                    <div class="flex justify-between items-start">
                    <div>
                        <h3 class="font-semibold text-gray-800 dark:text-white">{{ customer.name }} {{ customer.last_name }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ customer.email }}</p>
                    </div>
                    <span v-if="customer.status"
                        class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300 shrink-0">
                        Activo
                    </span>
                    <span v-else
                        class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300 shrink-0">
                        Suspendido
                    </span>
                    </div>

                    <div class="grid grid-cols-2 gap-1.5 text-sm">
                    <div><span class="text-gray-400">IP:</span> <span class="font-mono ml-1">{{ customer.ip_user || '-' }}</span></div>
                    <div><span class="text-gray-400">Plan:</span> <span class="ml-1">{{ customer.service_name || '-' }}</span></div>
                    <div class="flex items-center gap-1 flex-wrap">
                        <span class="text-gray-400">Router:</span>
                        <span class="ml-1">{{ customer.router_name || '-' }}</span>
                        <span v-if="customer.router_falla_general"
                            class="text-xs font-semibold text-red-600 dark:text-red-400"
                            title="Router en falla general">⚠ Falla</span>
                        <span v-if="customer.router_pppoe && !customer.pppoe_username"
                            class="text-xs font-medium text-amber-600 dark:text-amber-400"
                            title="Credenciales PPPoE no configuradas">⚠ PPPoE?</span>
                        <span v-else-if="customer.router_pppoe && customer.pppoe_username"
                            class="text-xs font-medium text-blue-500 dark:text-blue-400">PPPoE</span>
                    </div>
                    <div><span class="text-gray-400">Sectorial:</span> <span class="ml-1">{{ customer.sectorial_name || '-' }}</span></div>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                    <button v-if="can('customers.edit')" @click="router.push(`/customers/${customer.user_id}/edit`)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-blue-50 text-blue-700 border border-blue-200 hover:bg-blue-100 transition-all
                            dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800">
                        <icon-lucide-pencil class="w-3.5 h-3.5" /> Editar
                    </button>

                    <button v-if="can('customers.edit') && customer.status" @click="suspendCustomer(customer)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-orange-50 text-orange-700 border border-orange-200 hover:bg-orange-100 transition-all
                            dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800">
                        <icon-lucide-pause-circle class="w-3.5 h-3.5" /> Suspender
                    </button>

                    <button v-else-if="can('customers.edit') && !customer.status" @click="activateCustomer(customer)"
                        class="flex-1 min-w-[90px] px-3 py-2 text-xs font-medium rounded-lg flex items-center justify-center gap-1
                            bg-green-50 text-green-700 border border-green-200 hover:bg-green-100 transition-all
                            dark:bg-green-900/30 dark:text-green-300 dark:border-green-800">
                        <icon-lucide-play-circle class="w-3.5 h-3.5" /> Activar
                    </button>

                    <button v-if="can('customers.delete')" @click="deleteCustomer(customer)"
                        class="px-3 py-2 text-xs font-medium rounded-lg flex items-center gap-1
                            bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition-all
                            dark:bg-red-900/30 dark:text-red-300 dark:border-red-800">
                        <icon-lucide-trash-2 class="w-3.5 h-3.5" />
                    </button>
                    </div>
                </div>
                </div>

                <div v-if="filteredCustomers.length === 0" class="p-8 text-center text-gray-500 dark:text-gray-400">
                {{ searchQuery ? 'No se encontraron resultados' : 'No hay clientes registrados' }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import * as XLSX from 'xlsx'
import api from '../services/api'
import NotificationToast from '@/components/NotificationToast.vue'
import { usePermissions } from '@/composables/usePermissions'

const { can } = usePermissions()

const router         = useRouter()
const toast          = ref(null)
const customers      = ref([])
const loading        = ref(true)
const error          = ref('')
const searchQuery    = ref('')
const filterRouterId = ref(null)

// ── Confirm dialog state ────────────────────────────────────────────────────
const confirmDialog = ref({
    show: false,
    type: 'warning',       // 'info' | 'warning' | 'danger'
    icon: 'md-warning',
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    resolve: null,
})

const confirmIconBg = computed(() => ({
    'bg-green-100  dark:bg-green-900/30':  confirmDialog.value.type === 'info',
    'bg-amber-100  dark:bg-amber-900/30':  confirmDialog.value.type === 'warning',
    'bg-red-100    dark:bg-red-900/30':    confirmDialog.value.type === 'danger',
}))

const confirmIconColor = computed(() => ({
    'text-green-600  dark:text-green-400': confirmDialog.value.type === 'info',
    'text-amber-600  dark:text-amber-400': confirmDialog.value.type === 'warning',
    'text-red-600    dark:text-red-400':   confirmDialog.value.type === 'danger',
}))

const confirmBtnClass = computed(() => ({
    'bg-green-600 hover:bg-green-700':  confirmDialog.value.type === 'info',
    'bg-amber-500 hover:bg-amber-600':  confirmDialog.value.type === 'warning',
    'bg-red-600   hover:bg-red-700':    confirmDialog.value.type === 'danger',
}))

const openConfirm = (options) =>
    new Promise((resolve) => {
        confirmDialog.value = { show: true, resolve, ...options }
    })

const acceptConfirm = () => {
    confirmDialog.value.resolve(true)
    confirmDialog.value.show = false
}

const cancelConfirm = () => {
    confirmDialog.value.resolve(false)
    confirmDialog.value.show = false
}

// ── Router filter helpers ────────────────────────────────────────────────────
const availableRouters = computed(() => {
    const seen = new Set()
    const list = []
    for (const c of customers.value) {
        if (c.router_id && !seen.has(c.router_id)) {
            seen.add(c.router_id)
            list.push({ id: c.router_id, name: c.router_name, pppoe: !!c.router_pppoe })
        }
    }
    return list.sort((a, b) => a.name.localeCompare(b.name))
})

const selectedRouterInfo = computed(() =>
    filterRouterId.value
        ? availableRouters.value.find(r => r.id === filterRouterId.value) ?? null
        : null
)

const provisionBtnLabel = computed(() =>
    selectedRouterInfo.value ? `Cargar a ${selectedRouterInfo.value.name}` : 'Cargar a RB'
)

// ── Data loading ────────────────────────────────────────────────────────────
const filteredCustomers = computed(() => {
    let list = customers.value
    if (filterRouterId.value) {
        list = list.filter(c => c.router_id === filterRouterId.value)
    }
    if (!searchQuery.value) return list
    const q = searchQuery.value.toLowerCase().trim()
    return list.filter(c =>
        `${c.name} ${c.last_name}`.toLowerCase().includes(q) ||
        (c.email?.toLowerCase() || '').includes(q) ||
        (c.ip_user?.toLowerCase() || '').includes(q) ||
        (c.service_name?.toLowerCase() || '').includes(q) ||
        (c.router_name?.toLowerCase() || '').includes(q)
    )
})

const loadCustomers = async () => {
    try {
        loading.value = true
        const response = await api.customers.getAll()
        customers.value = response.data
    } catch (err) {
        console.error('Error al cargar clientes:', err)
        error.value = 'Error al cargar los clientes.'
    } finally {
        loading.value = false
    }
}

// ── Actions ─────────────────────────────────────────────────────────────────
const provisionCustomer = async () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin clientes', 'No hay clientes para provisionar. Ajusta tu búsqueda.')
        return
    }

    const count      = filteredCustomers.value.length
    const isSingle   = count === 1
    const c0         = filteredCustomers.value[0]
    const ri         = selectedRouterInfo.value
    const routerName = ri?.name ?? 'sus routers asignados'
    const detail     = ri
        ? (ri.pppoe ? 'queue de ancho de banda + PPPoE secret' : 'queue de ancho de banda')
        : 'queue de ancho de banda (PPPoE cuando aplique)'

    const confirmed = await openConfirm({
        type: 'info',
        icon: 'bi-server',
        title: isSingle ? 'Cargar al Router' : `Cargar ${count} clientes al Router`,
        message: isSingle
            ? `Se cargará a ${c0.name} ${c0.last_name} en ${routerName} (${detail}).`
            : `Se provisionarán ${count} clientes en ${routerName} (${detail}). Esta operación puede tardar unos segundos.`,
        confirmLabel: 'Cargar',
    })

    if (!confirmed) return

    try {
        loading.value = true
        const customerIds = filteredCustomers.value.map(c => c.user_id)
        const response    = await api.customers.bulkProvision(customerIds)

        const { success_count, fail_count, pppoe_skipped_count } = response.data

        if (fail_count > 0 && success_count > 0) {
            toast.value?.warning(
                'Provisionamiento parcial',
                `${success_count} exitoso(s), ${fail_count} con error.`
            )
        } else if (fail_count > 0) {
            toast.value?.error('Error al provisionar', `${fail_count} cliente(s) no pudieron ser provisionados.`)
        } else if (pppoe_skipped_count > 0) {
            toast.value?.warning(
                'Queue cargado — PPPoE pendiente',
                `Queue cargado en ${success_count} cliente(s). ${pppoe_skipped_count} cliente(s) con router PPPoE no tienen credenciales guardadas — edítalos para configurarlas.`
            )
        } else {
            const ri = selectedRouterInfo.value
            const suffix = ri?.pppoe ? ' (queue + PPPoE secret)' : ''
            toast.value?.success(
                'Provisionamiento exitoso',
                `${success_count} cliente(s) cargado(s) correctamente${suffix}.`
            )
        }
    } catch (err) {
        console.error('Error al provisionar:', err)
        toast.value?.error('Error de conexión', err.response?.data?.message || 'No se pudo conectar con el router.')
    } finally {
        loading.value = false
    }
}

const suspendCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'warning',
        icon: 'md-pausecircle',
        title: 'Suspender cliente',
        message: `¿Estás seguro de suspender a ${customer.name} ${customer.last_name}? Se bloqueará su acceso al servicio.`,
        confirmLabel: 'Suspender',
    })
    if (!confirmed) return

    try {
        loading.value = true
        const response = await api.customers.suspend(customer.user_id)
        toast.value?.success('Cliente suspendido', response.data.message || 'El acceso fue bloqueado correctamente.')
        loadCustomers()
    } catch (err) {
        const msg = err.response?.data?.message || 'Error al suspender el cliente.'
        if (err.response?.status === 400) {
            toast.value?.info('Sin cambios', msg)
            loadCustomers()
        } else {
            toast.value?.error('Error al suspender', msg)
        }
    } finally {
        loading.value = false
    }
}

const activateCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'info',
        icon: 'md-playcircle',
        title: 'Activar cliente',
        message: `¿Estás seguro de activar a ${customer.name} ${customer.last_name}? Se restaurará su acceso al servicio.`,
        confirmLabel: 'Activar',
    })
    if (!confirmed) return

    try {
        loading.value = true
        const response = await api.customers.activate(customer.user_id)
        toast.value?.success('Cliente activado', response.data.message || 'El acceso fue restaurado correctamente.')
        loadCustomers()
    } catch (err) {
        const msg = err.response?.data?.message || 'Error al activar el cliente.'
        if (err.response?.status === 400) {
            toast.value?.info('Sin cambios', msg)
            loadCustomers()
        } else {
            toast.value?.error('Error al activar', msg)
        }
    } finally {
        loading.value = false
    }
}

const deleteCustomer = async (customer) => {
    const confirmed = await openConfirm({
        type: 'danger',
        icon: 'md-deletepermanent',
        title: 'Eliminar cliente',
        message: `¿Estás seguro de eliminar a ${customer.name} ${customer.last_name}? Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
    })
    if (!confirmed) return

    try {
        await api.customers.delete(customer.user_id)
        toast.value?.success('Cliente eliminado', `${customer.name} ${customer.last_name} fue eliminado correctamente.`)
        loadCustomers()
    } catch (err) {
        console.error('Error al eliminar cliente:', err)
        toast.value?.error('Error al eliminar', err.response?.data?.message || 'No se pudo eliminar el cliente.')
    }
}

// ── Export ──────────────────────────────────────────────────────────────────
const exportRows = () =>
    filteredCustomers.value.map((c, idx) => ({
        '#': idx + 1,
        'Nombre': c.name || '',
        'Apellido': c.last_name || '',
        'Email': c.email || '',
        'IP': c.ip_user || '',
        'Plan': c.service_name || '',
        'Sectorial': c.sectorial_name || '',
        'Router': c.router_name || '',
        'Estado': c.status ? 'Activo' : 'Suspendido',
    }))

const downloadFile = (content, filename, mimeType) => {
    const blob = new Blob([content], { type: mimeType })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.setAttribute('href', url)
    link.setAttribute('download', filename)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

const exportToCSV = () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin datos', 'No hay clientes para exportar.')
        return
    }
    const rows = exportRows()
    const headers = Object.keys(rows[0])
    const csv = [
        headers.join(','),
        ...rows.map(r => headers.map(h => `"${String(r[h]).replace(/"/g, '""')}"`).join(',')),
    ].join('\n')

    const date = new Date().toISOString().split('T')[0]
    downloadFile('﻿' + csv, `clientes_${date}.csv`, 'text/csv;charset=utf-8;')
}

const exportToExcel = () => {
    if (filteredCustomers.value.length === 0) {
        toast.value?.warning('Sin datos', 'No hay clientes para exportar.')
        return
    }
    const rows = exportRows()
    const worksheet = XLSX.utils.json_to_sheet(rows)
    worksheet['!cols'] = [
        { wch: 5 },   // #
        { wch: 20 },  // Nombre
        { wch: 20 },  // Apellido
        { wch: 28 },  // Email
        { wch: 15 },  // IP
        { wch: 18 },  // Plan
        { wch: 18 },  // Sectorial
        { wch: 18 },  // Router
        { wch: 12 },  // Estado
    ]
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, worksheet, 'Clientes')

    const date = new Date().toISOString().split('T')[0]
    XLSX.writeFile(workbook, `clientes_${date}.xlsx`)
}

onMounted(loadCustomers)
</script>
